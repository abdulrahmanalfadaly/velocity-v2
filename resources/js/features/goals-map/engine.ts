// A port of the approved mockup engine (design/mockups/assets/goalsmap.js). It drives the DOM
// imperatively for smooth pan, zoom and layout animation, outside React.
/*
 * The Goals map: every goal, project, task and step as one living mind map,
 * growing out from you in the centre to both sides. Branch colour = the goal.
 * Task numbers are their places in your ranked list (the report formula), and a
 * glowing violet path runs from you to the task you should do next.
 */
import { Clock, dueInfo, fmt1, fmtHours } from '@/lib/velocity';
import type { BaseTask, HabitToday, NotReadyTask, RankedTask } from '@/types/velocity';

/* ---------------------------------------------------- data from the server */
export type MapRankedTask = RankedTask & { kind: 'ranked' };
export type MapDoneTask = BaseTask & { kind: 'done'; why: string };
export type MapTask = MapRankedTask | NotReadyTask | MapDoneTask;
export interface MapNote {
    id: number;
    title: string;
    updatedAt: string | null;
}
export interface MapProject {
    id: number;
    name: string;
    done: number;
    total: number;
    tasks: MapTask[];
    notes: MapNote[];
    habits: HabitToday[];
}
export interface MapGoal {
    id: number;
    title: string;
    why: string | null;
    target: string | null;
    color: number;
    icon: string | null;
    projects: MapProject[];
}

/* ------------------------------------------------------ nodes on the map */
type TaskState = 'waiting' | 'blocked' | 'later' | 'done' | 'doing' | 'overdue' | 'open';
interface NodeBase {
    id: string;
    title: string;
    w: number;
    h: number;
    children: MapNode[];
    open: boolean;
    x: number;
    cy: number;
    hue: number; // goal colour slot, 0 = none
    // Set by link() and layout():
    parent: MapNode | null;
    depth: number;
    kids: MapNode[];
    H: number;
    side: number;
    vis: boolean;
    state?: TaskState;
}
interface RootNode extends NodeBase {
    type: 'root';
}
interface InboxNode extends NodeBase {
    type: 'inbox';
}
interface GoalNode extends NodeBase {
    type: 'goal';
    goal: MapGoal;
}
interface ProjectNode extends NodeBase {
    type: 'project';
    pid: number;
    stats: { done: number; total: number };
}
interface TaskNode extends NodeBase {
    type: 'task';
    task: MapTask;
    state: TaskState;
    rank: number | null;
}
type RankedNode = TaskNode & { task: MapRankedTask; rank: number };
interface StepNode extends NodeBase {
    type: 'step';
    stepId: number;
    done: boolean;
}
interface NoteNode extends NodeBase {
    type: 'note';
    pid: number;
    noteId: number;
    when: string | null;
}
interface HabitNode extends NodeBase {
    type: 'habit';
    pid: number;
    habitId: number;
    streak: number;
    today: boolean;
}
type MapNode = RootNode | InboxNode | GoalNode | ProjectNode | TaskNode | StepNode | NoteNode | HabitNode;
type NodeType = MapNode['type'];
type NodeOf<K extends NodeType> = Extract<MapNode, { type: K }>;
type Auto = 'type' | 'w' | 'h' | 'children' | 'open' | 'x' | 'cy' | 'hue' | 'parent' | 'depth' | 'kids' | 'H' | 'side' | 'vis';
type NodeProps<K extends NodeType> = Omit<NodeOf<K>, Auto> & Partial<Pick<NodeBase, 'open' | 'hue'>>;
interface Frame {
    k: number;
    cx: number;
    cy: number;
}
const isRanked = (n: MapNode): n is RankedNode => n.type === 'task' && n.task.kind === 'ranked';

const P: Record<string, string> = {
    tasks: '<rect x="3.5" y="3.5" width="17" height="17" rx="4.5"/><path d="m8.5 12 2.5 2.5 4.5-5"/>',
    notes: '<path d="M14 3H7a2.5 2.5 0 0 0-2.5 2.5v13A2.5 2.5 0 0 0 7 21h10a2.5 2.5 0 0 0 2.5-2.5V8.5z"/><path d="M14 3v5.5h5.5M8.5 13h7M8.5 16.5h5"/>',
    habits: '<path d="m17 2.5 3.5 3.5L17 9.5"/><path d="M3.5 11.5V10A4 4 0 0 1 7.5 6h13"/><path d="m7 21.5-3.5-3.5L7 14.5"/><path d="M20.5 12.5V14a4 4 0 0 1-4 4h-13"/>',
    search: '<circle cx="11" cy="11" r="6.5"/><path d="m20.5 20.5-4.8-4.8"/>',
    plus: '<path d="M12 5v14M5 12h14"/>',
    minus: '<path d="M5 12h14"/>',
    sliders: '<path d="M4 7h9M17 7h3M4 17h3M11 17h9"/><circle cx="15" cy="7" r="2"/><circle cx="9" cy="17" r="2"/>',
    chevR: '<path d="m9.5 6 6 6-6 6"/>',
    inbox: '<path d="M3.5 13.5h5l1.5 2.5h4l1.5-2.5h5"/><path d="M6 5h12l2.5 8.5v5a2 2 0 0 1-2 2h-13a2 2 0 0 1-2-2v-5z"/>',
    rank: '<path d="M4 6.5h9M4 12h6.5M4 17.5h4"/><path d="M18 19.5v-14M15 8.5l3-3 3 3"/>',
    hourglass:
        '<path d="M6.5 3h11M6.5 21h11"/><path d="M7.5 3v3a4.5 4.5 0 0 0 1.8 3.6L12 12l2.7-2.4A4.5 4.5 0 0 0 16.5 6V3M7.5 21v-3a4.5 4.5 0 0 1 1.8-3.6L12 12l2.7 2.4a4.5 4.5 0 0 1 1.8 3.6v3"/>',
    folder: '<path d="M3.5 7.5a2 2 0 0 1 2-2h4l2 2.5h7a2 2 0 0 1 2 2v7.5a2 2 0 0 1-2 2h-13a2 2 0 0 1-2-2z"/>',
    target: '<circle cx="12" cy="12" r="8.5"/><circle cx="12" cy="12" r="4.5"/><circle cx="12" cy="12" r=".8"/>',
    x: '<path d="M18 6 6 18M6 6l12 12"/>',
    check: '<path d="m5 12.5 4.5 4.5L19 7.5"/>',
    calendar: '<rect x="3.5" y="5" width="17" height="15.5" rx="3"/><path d="M3.5 10h17M8.5 3v4M15.5 3v4"/>',
    lock: '<rect x="5" y="10.5" width="14" height="10" rx="2.5"/><path d="M8.5 10.5v-3a3.5 3.5 0 0 1 7 0v3"/>',
    info: '<circle cx="12" cy="12" r="8.5"/><path d="M12 11v5M12 7.8h.01"/>',
    flame: '<path d="M12 21.5c3.9 0 6.5-2.6 6.5-6.4 0-4.1-3.4-6.6-4.6-10.1-1.8 1.6-2.7 3.5-2.7 5.4-1.3-.9-1.9-2.2-2-3.6-2.3 2.3-3.7 5-3.7 8.3 0 3.8 2.6 6.4 6.5 6.4z"/>',
    cap: '<path d="m2.5 9 9.5-4.5L21.5 9 12 13.5z"/><path d="M6.5 11v4.5c0 1.4 2.5 3 5.5 3s5.5-1.6 5.5-3V11M21.5 9v5"/>',
    briefcase: '<rect x="3" y="7" width="18" height="13" rx="2.5"/><path d="M8.5 7V5.5a2 2 0 0 1 2-2h3a2 2 0 0 1 2 2V7M3 12.5h18"/>',
    heart: '<path d="M12 20s-7.5-4.4-7.5-10A4.3 4.3 0 0 1 12 7.3 4.3 4.3 0 0 1 19.5 10c0 5.6-7.5 10-7.5 10z"/>',
    expand: '<path d="M14.5 3.5h6v6M9.5 20.5h-6v-6M20.5 3.5 14 10M3.5 20.5 10 14"/>',
    zap: '<path d="M13 2.5 4.5 13.5h7l-1 8 8.5-11h-7z"/>',
    menu: '<path d="M4 7h16M4 12h16M4 17h16"/>',
    list: '<path d="M9 6.5h11M9 12h11M9 17.5h11M4.5 6.5h.01M4.5 12h.01M4.5 17.5h.01"/>',
    branch: '<circle cx="6" cy="6" r="2.2"/><circle cx="6" cy="18" r="2.2"/><circle cx="18" cy="12" r="2.2"/><path d="M6 8.2v7.6M8 6.8c5 .4 7.5 1.8 8.3 3.6M8 17.2c5-.4 7.5-1.8 8.3-3.6"/>',
    arrowUpRight: '<path d="M7 17 17 7M8.5 7H17v8.5"/>',
};
const ic = (name: string, cls = '') => `<svg class="ic ${cls}" viewBox="0 0 24 24" aria-hidden="true">${P[name] || P.info}</svg>`;
const ESC: Record<string, string> = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' };
const esc = (s: unknown) => String(s ?? '').replace(/[&<>"']/g, (c) => ESC[c]);
const progressRing = (pct: number, size = 44, stroke = 5) => {
    const r = (size - stroke) / 2;
    const c = 2 * Math.PI * r;
    return `<svg class="progress-ring" width="${size}" height="${size}" viewBox="0 0 ${size} ${size}" aria-hidden="true"><circle class="progress-ring-track" cx="${size / 2}" cy="${size / 2}" r="${r}" stroke-width="${stroke}"/><circle class="progress-ring-fill" cx="${size / 2}" cy="${size / 2}" r="${r}" stroke-width="${stroke}" stroke-dasharray="${c.toFixed(2)}" stroke-dashoffset="${(c * (1 - pct / 100)).toFixed(2)}" transform="rotate(-90 ${size / 2} ${size / 2})"/></svg>`;
};
const KIND_ICON: Record<string, string> = { waiting: 'hourglass', blocked: 'lock', later: 'calendar', done: 'check' };
const KIND_LABEL: Record<string, string> = { waiting: 'Waiting', blocked: 'Blocked', later: 'Not yet', done: 'Done' };
const clamp = (v: number, a: number, b: number) => Math.max(a, Math.min(b, v));

/** What GoalController sends. */
export interface MapData {
    goals: MapGoal[];
    inbox: MapTask[];
    nextId: number | null;
    totalRanked: number;
    focus: string | null;
    userName: string;
}
export interface MapContext {
    clock: Clock;
    onDone: (taskId: number) => void;
    onPromote: (stepId: number) => void;
    onToggleMenu: () => void;
    onNewGoal: () => void;
    onEditGoal: (goalId: number) => void;
}

export function mountGoalsMap(mount: HTMLElement, data: MapData, ctx: MapContext): () => void {
    const reduceMotion = matchMedia('(prefers-reduced-motion: reduce)').matches;
    const cleanups: (() => void)[] = [];
    const on = <K extends keyof HTMLElementEventMap>(
        el: HTMLElement | Document,
        ev: K,
        fn: (e: HTMLElementEventMap[K]) => void,
        opts?: AddEventListenerOptions,
    ) => {
        el.addEventListener(ev, fn as EventListener, opts);
        cleanups.push(() => el.removeEventListener(ev, fn as EventListener, opts));
    };
    const clock = ctx.clock;

    /* ------------------------------------------------------------ the tree */
    const SIZE: Record<NodeType, [number, number]> = {
        root: [176, 176],
        goal: [292, 84],
        inbox: [240, 60],
        project: [248, 64],
        task: [296, 56],
        step: [218, 34],
        note: [218, 36],
        habit: [218, 36],
    };
    const COLS = [0, 156, 524, 860, 1244, 1580];
    const GAP: Record<string, number> = { goal: 64, inbox: 64, project: 24, task: 12, step: 6, note: 8, habit: 8 };
    const WIDTH: Record<number, [number, number]> = { 1: [26, 9], 2: [9, 4.4], 3: [4.2, 2.4], 4: [2.4, 1.4], 5: [1.8, 1.2] };
    const nodes = new Map<string, MapNode>();
    let NEXT = null as TaskNode | null; // `as` keeps TypeScript from assuming it stays null
    const make = <K extends NodeType>(type: K, props: NodeProps<K>, children: MapNode[] = []): NodeOf<K> => {
        const n = {
            type,
            w: SIZE[type][0],
            h: SIZE[type][1],
            children,
            open: true,
            x: 0,
            cy: 0,
            hue: 0,
            parent: null,
            depth: 0,
            kids: [],
            H: 0,
            side: 0,
            vis: false,
            ...props,
        } as unknown as NodeOf<K>;
        nodes.set(n.id, n);
        return n;
    };
    const stateOf = (t: MapTask): TaskState =>
        t.kind !== 'ranked' ? t.kind : t.status === 'doing' ? 'doing' : t.days != null && t.days <= 0 ? 'overdue' : 'open';
    const orderOf = (t: MapTask) => (t.kind === 'ranked' ? t.rank : t.kind === 'done' ? 10000 + t.id : 5000 + t.id);
    function taskNodes(tasks: MapTask[], hue: number): TaskNode[] {
        const byParent = new Map<number, MapTask[]>();
        const ids = new Set(tasks.map((t) => t.id));
        tasks.forEach((t) => {
            const key = t.parent && ids.has(t.parent.id) ? t.parent.id : 0;
            if (!byParent.has(key)) byParent.set(key, []);
            byParent.get(key)!.push(t);
        });
        const build = (t: MapTask): TaskNode => {
            const steps = (t.steps || []).map((s) => make('step', { id: `s${s.id}`, stepId: s.id, title: s.title, done: s.done, hue }));
            const kids = (byParent.get(t.id) || []).sort((a, b) => orderOf(a) - orderOf(b)).map(build);
            const n = make(
                'task',
                {
                    id: `t${t.id}`,
                    title: t.title,
                    task: t,
                    state: stateOf(t),
                    hue,
                    rank: t.kind === 'ranked' ? t.rank : null,
                    open: t.id === data.nextId,
                },
                [...steps, ...kids],
            );
            if (t.id === data.nextId) NEXT = n;
            return n;
        };
        return (byParent.get(0) || []).sort((a, b) => orderOf(a) - orderOf(b)).map(build);
    }
    const root = make('root', { id: 'root', title: 'You' });
    data.goals.forEach((g) => {
        const projects = g.projects.map((p) => {
            const kids: MapNode[] = taskNodes(p.tasks, g.color);
            p.notes.forEach((nt) =>
                kids.push(make('note', { id: `n${nt.id}`, noteId: nt.id, title: nt.title, when: nt.updatedAt, hue: g.color, pid: p.id })),
            );
            p.habits.forEach((h) =>
                kids.push(
                    make('habit', { id: `h${h.id}`, habitId: h.id, title: h.name, streak: h.streak, today: h.doneToday, hue: g.color, pid: p.id }),
                ),
            );
            return make('project', { id: `p${p.id}`, title: p.name, pid: p.id, hue: g.color, stats: { done: p.done, total: p.total } }, kids);
        });
        root.children.push(make('goal', { id: `g${g.id}`, title: g.title, goal: g, hue: g.color }, projects));
    });
    if (data.inbox.length) root.children.push(make('inbox', { id: 'inbox', title: 'Inbox', hue: 0 }, taskNodes(data.inbox, 0)));
    (function link(n: MapNode, parent: MapNode | null, depth: number) {
        n.parent = parent;
        n.depth = depth;
        n.children.forEach((c) => link(c, n, depth + 1));
    })(root, null, 0);

    function tally(n: MapNode) {
        let done = 0;
        let total = 0;
        (function walk(m: MapNode) {
            if (m.type === 'task') {
                total += 1;
                if (m.state === 'done') done += 1;
            }
            m.children.forEach(walk);
        })(n);
        if (n.type === 'project' && n.stats) {
            done = n.stats.done;
            total = n.stats.total;
        }
        return { done, total, pct: total ? Math.round((done / total) * 100) : 0 };
    }
    const count = (n: MapNode, type: NodeType) => n.children.filter((c) => c.type === type).length;

    /* ---------------------------------------------------------------- layout */
    const show = { path: true, extras: true, done: true };
    const kidsOf = (n: MapNode) =>
        n.open ? n.children.filter((c) => (show.extras || (c.type !== 'note' && c.type !== 'habit')) && (show.done || c.state !== 'done')) : [];
    function measure(n: MapNode): number {
        n.kids = kidsOf(n);
        if (!n.kids.length) return (n.H = n.h);
        let sum = 0;
        n.kids.forEach((k, i) => {
            sum += measure(k) + (i ? GAP[k.type] : 0);
        });
        return (n.H = Math.max(n.h, sum));
    }
    function place(n: MapNode, top: number) {
        if (!n.kids.length) {
            n.cy = top + n.H / 2;
            return;
        }
        const sum = n.kids.reduce((s, k, i) => s + k.H + (i ? GAP[k.type] : 0), 0);
        let y = top + (n.H - sum) / 2;
        n.kids.forEach((k, i) => {
            if (i) y += GAP[k.type];
            place(k, y);
            y += k.H;
        });
        n.cy = (n.kids[0].cy + n.kids[n.kids.length - 1].cy) / 2;
    }
    function setX(n: MapNode, side: number) {
        n.side = side;
        const inner = COLS[Math.min(n.depth, COLS.length - 1)] + Math.max(0, n.depth - (COLS.length - 1)) * 330;
        n.x = side > 0 ? inner : -inner - n.w;
        n.kids.forEach((k) => setX(k, side));
    }
    let VIS: MapNode[] = [];
    function layout() {
        root.kids = kidsOf(root);
        root.kids.forEach(measure);
        const side: Record<'L' | 'R', MapNode[]> = { L: [], R: [] };
        const used = { L: 0, R: 0 };
        root.kids
            .slice()
            .sort((a, b) => b.H - a.H)
            .forEach((g) => {
                const s = used.R <= used.L ? 'R' : 'L';
                side[s].push(g);
                used[s] += g.H + GAP.goal;
            });
        (['L', 'R'] as const).forEach((s) => {
            const arr = side[s].sort((a, b) => root.kids.indexOf(a) - root.kids.indexOf(b));
            const total = arr.reduce((t, g, i) => t + g.H + (i ? GAP[g.type] : 0), 0);
            let y = -total / 2;
            arr.forEach((g, i) => {
                if (i) y += GAP[g.type];
                place(g, y);
                y += g.H;
                setX(g, s === 'R' ? 1 : -1);
            });
        });
        root.x = -root.w / 2;
        root.cy = 0;
        root.side = 0;
        nodes.forEach((n) => {
            n.vis = false;
        });
        VIS = [];
        (function mark(n: MapNode) {
            n.vis = true;
            VIS.push(n);
            (n.kids || []).forEach(mark);
        })(root);
    }
    function bounds(arr: MapNode[]) {
        let x0 = Infinity,
            y0 = Infinity,
            x1 = -Infinity,
            y1 = -Infinity;
        arr.forEach((n) => {
            x0 = Math.min(x0, n.x);
            x1 = Math.max(x1, n.x + n.w);
            y0 = Math.min(y0, n.cy - n.h / 2);
            y1 = Math.max(y1, n.cy + n.h / 2);
        });
        return { x0, y0, x1, y1 };
    }
    const subtree = (n: MapNode) => {
        const out: MapNode[] = [];
        (function walk(m: MapNode) {
            if (m.vis) {
                out.push(m);
                m.kids.forEach(walk);
            }
        })(n);
        return out;
    };

    /* ------------------------------------------------------------------- DOM */
    const goalNode = (g: MapGoal) => nodes.get(`g${g.id}`)!;
    // "Other projects" (id 0) holds projects with no goal; it is not counted as a goal.
    const goalCount = data.goals.filter((g) => g.id > 0).length;
    const totals = tally(root);
    const projectCount = data.goals.reduce((s, g) => s + g.projects.length, 0);
    const initials = data.userName
        .split(' ')
        .map((w) => w[0])
        .join('')
        .slice(0, 2)
        .toUpperCase();
    mount.innerHTML = `
        <div class="gm-bar">
            <button class="icon-btn gm-menu" data-gm-side title="Show the Tasks menu" aria-label="Show the Tasks menu">${ic('menu')}</button>
            <div class="gm-title"><h1>Goals map</h1><span>${goalCount} goals · ${projectCount} projects · ${totals.total} tasks · ${totals.pct}% done</span></div>
            <div class="gm-goals" role="group" aria-label="Jump to a goal">${data.goals.map((g) => `<button class="gm-goal" data-gm-goal="g${g.id}" aria-pressed="false" style="--g:var(--goal-${g.color})"><span class="gdot">${ic(g.icon || 'target')}</span><span class="gm-goal-name">${esc(g.title)}</span><b>${tally(goalNode(g)).pct}%</b></button>`).join('')}</div>
            <div class="gm-tools">
                <label class="gm-search">${ic('search', 'ic-sm')}<input data-gm-search placeholder="Find in the map" aria-label="Find in the map" autocomplete="off"><small data-gm-count></small></label>
                <div class="pop-wrap">
                    <button class="btn btn-secondary btn-sm" data-gm-newgoal>${ic('plus', 'ic-sm')}<span class="btn-label">New goal</span></button>
                    <button class="btn btn-secondary btn-sm" data-gm-layers aria-expanded="false" aria-controls="gm-layers">${ic('sliders', 'ic-sm')}<span class="btn-label">Show</span></button>
                    <div class="popover gm-layers" id="gm-layers" role="dialog" aria-label="What to show" hidden>
                        <p class="pop-title">Show on the map</p>
                        <label class="gm-check"><input type="checkbox" data-gm-show="path" checked><span><b>Path to your next task</b>The glowing line from you to #1</span></label>
                        <label class="gm-check"><input type="checkbox" data-gm-show="extras" checked><span><b>Notes and habits</b>Each project’s notes and habits</span></label>
                        <label class="gm-check"><input type="checkbox" data-gm-show="done" checked><span><b>Done tasks</b>Finished in the last 3 weeks, faded</span></label>
                        <div class="pop-foot"><button class="link" data-gm-unfold>Unfold everything</button><button class="link" data-gm-fold>Fold to projects</button></div>
                    </div>
                </div>
                <div class="gm-seg" role="group" aria-label="View">
                    <button data-gm-mode="map" aria-pressed="true">${ic('branch', 'ic-sm')}Map</button>
                    <button data-gm-mode="outline" aria-pressed="false">${ic('list', 'ic-sm')}Outline</button>
                </div>
            </div>
        </div>
        <div class="gm-stage">
            <div class="gm-view" tabindex="0" role="application" aria-label="Goals map. Drag to move, scroll or pinch to zoom, press 0 to see everything.">
                <div class="gm-world">
                    <div class="gm-aura"></div>
                    <svg class="gm-links" width="1" height="1" aria-hidden="true"><g data-links></g><path class="gm-next-glow" data-next-glow d=""/><path class="gm-next" id="gm-next-path" d=""/><g data-sparks></g></svg>
                    <svg class="gm-links gm-flows" width="1" height="1" aria-hidden="true"><g data-flows></g></svg>
                    <div class="gm-nodes"></div>
                </div>
            </div>
            <details class="gm-key"><summary>${ic('info', 'ic-sm')}How to read it</summary>
                <ul><li><i class="k k-goal"></i>Goal</li><li><i class="k k-project"></i>Project</li><li><i class="k k-task">3</i>Task, with its rank</li><li><i class="k k-step"></i>Step</li><li><i class="k k-note"></i>Note</li><li><i class="k k-habit"></i>Habit</li><li><i class="k k-next"></i>Path to your next task</li><li><i class="k k-late"></i>Overdue</li><li><i class="k k-wait"></i>Waiting or blocked</li></ul>
                <p>Branch colour shows which goal it belongs to. Thicker branches sit closer to you.</p>
            </details>
            <div class="gm-mini" aria-hidden="true"><svg data-mini preserveAspectRatio="xMidYMid meet"><g data-mini-nodes></g><rect class="vp" data-mini-vp rx="12"/></svg></div>
            <div class="gm-zoom" role="group" aria-label="Zoom">
                <button data-gm-zoom="out" aria-label="Zoom out" title="Zoom out (−)">${ic('minus')}</button><output data-gm-k>100%</output><button data-gm-zoom="in" aria-label="Zoom in" title="Zoom in (+)">${ic('plus')}</button>
                <span class="gm-zsep"></span>
                <button data-gm-fit aria-label="See everything" title="See everything (0)">${ic('expand')}</button><button data-gm-gonext aria-label="Go to your next task" title="Go to your next task (N)">${ic('zap')}</button>
            </div>
            <aside class="gm-panel" aria-label="Details" hidden></aside>
        </div>
        <div class="gm-outline" hidden></div>`;

    // Every element looked up here is in the markup just written above.
    const $ = <T extends Element = HTMLElement>(s: string) => mount.querySelector<T>(s)!;
    const view = $('.gm-view');
    const world = $('.gm-world');
    const linksG = $<SVGGElement>('[data-links]');
    const nextPath = $<SVGPathElement>('#gm-next-path');
    const nextGlow = $<SVGPathElement>('[data-next-glow]');
    const sparksG = $<SVGGElement>('[data-sparks]');
    const flowsG = $<SVGGElement>('[data-flows]');
    const nodesEl = $('.gm-nodes');
    const miniSvg = $<SVGSVGElement>('[data-mini]');
    const miniNodes = $<SVGGElement>('[data-mini-nodes]');
    const miniVp = $<SVGRectElement>('[data-mini-vp]');
    const panel = $('.gm-panel');
    const outline = $('.gm-outline');
    const kOut = $<HTMLOutputElement>('[data-gm-k]');
    const searchInput = $<HTMLInputElement>('[data-gm-search]');
    const searchCount = $('[data-gm-count]');
    const showBox = (key: keyof typeof show) => $<HTMLInputElement>(`[data-gm-show="${key}"]`);
    const els = new Map<string, HTMLDivElement>();
    const nodeOf = (id: string | undefined) => (id ? nodes.get(id) : undefined);
    let selectedId = null as string | null;
    let lit = null as Set<string> | null;

    function label(n: MapNode) {
        switch (n.type) {
            case 'root':
                return `You: ${goalCount} goals, ${totals.pct}% of tasks done`;
            case 'goal':
                return `Goal: ${n.title}, ${tally(n).pct}% done`;
            case 'project':
                return `Project: ${n.title}, ${tally(n).done} of ${tally(n).total} tasks done`;
            case 'inbox':
                return `Inbox: ${n.children.length} tasks not in a project`;
            case 'task':
                return isRanked(n) ? `Task #${n.rank}: ${n.title}, priority ${fmt1(n.task.score)}` : `Task: ${n.title}, ${KIND_LABEL[n.state] ?? ''}`;
            case 'step':
                return `Step: ${n.title}${n.done ? ', done' : ''}`;
            case 'note':
                return `Note: ${n.title}`;
            default:
                return `Habit: ${n.title}, ${n.streak}-day streak`;
        }
    }
    function classOf(n: MapNode) {
        const c = ['mn', `mn-${n.type}`, `h${n.hue}`];
        if (n.type === 'task') c.push(`is-${n.state}`);
        if (n === NEXT) c.push('is-next');
        if ((n.type === 'step' && n.done) || (n.type === 'habit' && n.today)) c.push('is-done');
        if (n.id === selectedId) c.push('is-selected');
        if (lit && lit.has(n.id)) c.push('is-lit');
        return c.join(' ');
    }
    function knob(n: MapNode) {
        if (n.type === 'root' || !n.children.length) return '';
        return `<button class="mn-knob ${n.side > 0 ? 'r' : 'l'}${n.open ? '' : ' is-closed'}" data-knob="${n.id}" aria-expanded="${n.open}" aria-label="${n.open ? 'Fold' : 'Unfold'} ${esc(n.title)}" title="${n.open ? 'Fold' : `Show ${n.children.length} inside`}">${n.open ? ic('minus', 'ic-xs') : `+${n.children.length}`}</button>`;
    }
    function inner(n: MapNode) {
        switch (n.type) {
            case 'root':
                return `<span class="mn-orbit"></span>${progressRing(totals.pct, 196, 5)}<span class="avatar">${esc(initials)}</span><b>${esc(data.userName.split(' ')[0])}</b><span>${goalCount} goals · ${totals.pct}% done</span>`;
            case 'goal': {
                const t = tally(n);
                return `<span class="gdot">${ic(n.goal.icon || 'target')}</span><div class="mn-body"><div class="mn-title">${esc(n.title)}</div><div class="mn-meta">${count(n, 'project')} projects${n.goal.target ? ` · by ${esc(n.goal.target)}` : ''}</div></div><span class="mn-pct">${progressRing(t.pct, 44, 4)}<span>${t.pct}%</span></span>${knob(n)}`;
            }
            case 'inbox':
                return `<span class="pdot">${ic('inbox')}</span><div class="mn-body"><div class="mn-title">Inbox</div><div class="mn-meta">${n.children.length} tasks not in a project yet</div></div>${knob(n)}`;
            case 'project': {
                const t = tally(n);
                const notes = count(n, 'note');
                const habits = count(n, 'habit');
                return `<span class="pdot">${ic('folder')}</span><div class="mn-body"><div class="mn-title">${esc(n.title)}</div><div class="mn-bar"><i style="--p:${t.pct}%"></i><span>${t.done}/${t.total}</span></div></div><div class="mn-badges">${notes ? `<span title="${notes} notes">${ic('notes')}${notes}</span>` : ''}${habits ? `<span title="${habits} habits">${ic('habits')}${habits}</span>` : ''}</div>${knob(n)}`;
            }
            case 'task': {
                const t = n.task;
                if (t.kind === 'ranked') {
                    const due = dueInfo(t, clock);
                    const steps = t.steps || [];
                    const meta = [`<span${due.late ? ' class="is-late"' : ''}>${due.text}</span>`, fmtHours(t.estHours)];
                    if (steps.length) meta.push(`${steps.filter((s) => s.done).length}/${steps.length} steps`);
                    return `${n === NEXT ? `<span class="mn-tag">${ic('zap', 'ic-xs')}Do next</span>` : ''}<span class="rank-badge">${n.rank}</span><div class="mn-body"><div class="mn-title">${esc(n.title)}</div><div class="mn-meta">${meta.join(' · ')}</div></div><span class="mn-score">${fmt1(t.score)}</span>${knob(n)}`;
                }
                const why = n.state === 'done' ? (t.completedAt ? `${clock.date(t.completedAt)}` : '') : t.why;
                return `<span class="kind">${ic(KIND_ICON[n.state] || 'info')}</span><div class="mn-body"><div class="mn-title">${esc(n.title)}</div><div class="mn-meta">${KIND_LABEL[n.state] ?? ''} · ${esc(why || '')}</div></div>${knob(n)}`;
            }
            case 'step':
                return `<span class="sdot">${ic('check')}</span><span class="mn-title">${esc(n.title)}</span>`;
            case 'note':
                return `${ic('notes')}<span class="mn-title">${esc(n.title)}</span>`;
            default:
                return `${ic('habits')}<span class="mn-title">${esc(n.title)}</span><b title="${n.streak}-day streak">${ic('flame', 'ic-xs')}${n.streak}</b>`;
        }
    }
    function syncNodes() {
        const keep = new Set(VIS.map((n) => n.id));
        els.forEach((el, id) => {
            if (!keep.has(id)) {
                el.remove();
                els.delete(id);
            }
        });
        VIS.forEach((n, i) => {
            let el = els.get(n.id);
            if (!el) {
                el = document.createElement('div');
                el.dataset.id = n.id;
                el.setAttribute('role', 'button');
                el.style.setProperty('--d', String(n.depth));
                el.style.setProperty('--i', String(i % 24));
                nodesEl.appendChild(el);
                els.set(n.id, el);
            }
            el.className = classOf(n);
            el.innerHTML = inner(n);
            el.setAttribute('aria-label', label(n));
            el.style.width = `${n.w}px`;
            el.style.height = `${n.h}px`;
        });
        nodesEl.appendChild(els.get('root')!);
    }
    function positionNodes() {
        VIS.forEach((n) => {
            const el = els.get(n.id)!;
            el.style.left = `${n.x.toFixed(1)}px`;
            el.style.top = `${(n.cy - n.h / 2).toFixed(1)}px`;
        });
    }

    /* -------------------------------------------------------------- branches */
    // A branch is one cubic Bézier: [x0, y0, cx1, cy1, cx2, cy2, x1, y1].
    type Curve = [number, number, number, number, number, number, number, number];
    const bez = (a: number, b: number, c: number, d: number, t: number) => {
        const u = 1 - t;
        return u * u * u * a + 3 * u * u * t * b + 3 * u * t * t * c + t * t * t * d;
    };
    const dbez = (a: number, b: number, c: number, d: number, t: number) => {
        const u = 1 - t;
        return 3 * u * u * (b - a) + 6 * u * t * (c - b) + 3 * t * t * (d - c);
    };
    function ribbon(Pt: Curve, w0: number, w1: number) {
        const L: string[] = [];
        const R: string[] = [];
        for (let i = 0; i <= 30; i += 1) {
            const t = i / 30;
            const x = bez(Pt[0], Pt[2], Pt[4], Pt[6], t);
            const y = bez(Pt[1], Pt[3], Pt[5], Pt[7], t);
            let dx = dbez(Pt[0], Pt[2], Pt[4], Pt[6], t);
            let dy = dbez(Pt[1], Pt[3], Pt[5], Pt[7], t);
            const len = Math.hypot(dx, dy) || 1;
            dx /= len;
            dy /= len;
            const hw = (w0 + (w1 - w0) * (1 - (1 - t) ** 1.7)) / 2;
            L.push(`${(x - dy * hw).toFixed(1)},${(y + dx * hw).toFixed(1)}`);
            R.push(`${(x + dy * hw).toFixed(1)},${(y - dx * hw).toFixed(1)}`);
        }
        return `M${L.join('L')}L${R.reverse().join('L')}Z`;
    }
    function controls(p: MapNode, c: MapNode): Curve {
        const s = c.side;
        const x1 = s > 0 ? c.x : c.x + c.w;
        const y1 = c.cy;
        if (p.type === 'root') return [0, 0, s * 130, y1 * 0.3, x1 - s * 120, y1, x1, y1];
        const x0 = s > 0 ? p.x + p.w : p.x;
        const y0 = p.cy;
        const k = Math.abs(x1 - x0) * 0.56;
        return [x0, y0, x0 + s * k, y0, x1 - s * k, y1, x1, y1];
    }
    const curve = (Pt: Curve) =>
        `M${Pt[0].toFixed(1)},${Pt[1].toFixed(1)} C${Pt[2].toFixed(1)},${Pt[3].toFixed(1)} ${Pt[4].toFixed(1)},${Pt[5].toFixed(1)} ${Pt[6].toFixed(1)},${Pt[7].toFixed(1)}`;
    function drawLinks() {
        let out = '';
        VIS.forEach((c) => {
            if (!c.parent) return;
            const Pt = controls(c.parent, c);
            const cls = `lnk h${c.hue} d${Math.min(c.depth, 4)}${c.state === 'done' ? ' is-done' : ''}${lit && lit.has(c.id) ? ' is-lit' : ''}`;
            if (c.type === 'note' || c.type === 'habit') out += `<path class="${cls} lnk-soft" data-c="${c.id}" d="${curve(Pt)}"/>`;
            else {
                const [w0, w1] = WIDTH[Math.min(c.depth, 5)];
                out += `<path class="${cls}" data-c="${c.id}" d="${ribbon(Pt, w0, w1)}"/>`;
            }
        });
        linksG.innerHTML = out;
    }
    function drawNext() {
        if (!NEXT) return;
        let chain: MapNode[] = [];
        for (let n: MapNode | null = NEXT; n; n = n.parent) chain.unshift(n);
        chain = chain.filter((n) => n.vis);
        if (!show.path || chain.length < 2) {
            nextPath.setAttribute('d', '');
            nextGlow.setAttribute('d', '');
            sparksG.style.display = 'none';
            return;
        }
        let d = '';
        for (let i = 1; i < chain.length; i += 1) {
            const Pt = controls(chain[i - 1], chain[i]);
            d += `${i === 1 ? `M${Pt[0]},${Pt[1]}` : ` L${Pt[0].toFixed(1)},${Pt[1].toFixed(1)}`} C${Pt[2].toFixed(1)},${Pt[3].toFixed(1)} ${Pt[4].toFixed(1)},${Pt[5].toFixed(1)} ${Pt[6].toFixed(1)},${Pt[7].toFixed(1)}`;
        }
        const last = chain[chain.length - 1];
        d += ` L${(last.side > 0 ? last.x + 34 : last.x + last.w - 34).toFixed(1)},${last.cy.toFixed(1)}`;
        nextPath.setAttribute('d', d);
        nextGlow.setAttribute('d', d);
        sparksG.style.display = reduceMotion ? 'none' : '';
    }
    if (!reduceMotion) {
        sparksG.innerHTML = [0, 1, 2, 3, 4, 5]
            .map(
                (i) =>
                    `<circle class="gm-spark" r="${i % 3 === 0 ? 4.5 : 3}"><animateMotion dur="4.8s" repeatCount="indefinite" begin="${(-i * 0.8).toFixed(1)}s" calcMode="spline" keyPoints="0;1" keyTimes="0;1" keySplines="0.35 0 0.25 1"><mpath href="#gm-next-path"/></animateMotion></circle>`,
            )
            .join('');
    }

    /* Dots flowing out from you along every other branch, in its goal's colour.
       They sit in their own layer so moving them never repaints the nodes. */
    const FLOW_SPEED = 70; // px per second
    const FLOW_GAP = 130; // px between dots on one branch
    const FLOW_R: Record<number, number> = { 1: 3, 2: 2.6, 3: 2.1 };
    function curveLength(Pt: Curve) {
        let len = 0;
        let px = Pt[0];
        let py = Pt[1];
        for (let i = 1; i <= 20; i += 1) {
            const x = bez(Pt[0], Pt[2], Pt[4], Pt[6], i / 20);
            const y = bez(Pt[1], Pt[3], Pt[5], Pt[7], i / 20);
            len += Math.hypot(x - px, y - py);
            px = x;
            py = y;
        }
        return len;
    }
    // A fixed offset per branch, so dots on sibling branches don't move in step and a redraw keeps them in place.
    const phaseOf = (id: string) => {
        let h = 0;
        for (const ch of id) h = (h * 31 + ch.charCodeAt(0)) % 9973;
        return h / 9973;
    };
    function drawFlows({ fade = 0, delay = 0 }: { fade?: number; delay?: number } = {}) {
        if (reduceMotion) return;
        const onPath = new Set<string>();
        if (show.path && NEXT) for (let n: MapNode | null = NEXT; n; n = n.parent) onPath.add(n.id);
        let out = '';
        VIS.forEach((c) => {
            if (!c.parent || onPath.has(c.id)) return;
            const Pt = controls(c.parent, c);
            const s = c.side;
            // Start and end a little inside the two nodes, so dots slide out from under one and in under the other.
            const tuck = c.parent.type === 'root' ? 0 : 6;
            const d = `M${(Pt[0] - s * tuck).toFixed(1)},${Pt[1].toFixed(1)} L${Pt[0].toFixed(1)},${Pt[1].toFixed(1)} C${Pt[2].toFixed(1)},${Pt[3].toFixed(1)} ${Pt[4].toFixed(1)},${Pt[5].toFixed(1)} ${Pt[6].toFixed(1)},${Pt[7].toFixed(1)} L${(Pt[6] + s * 6).toFixed(1)},${Pt[7].toFixed(1)}`;
            const len = curveLength(Pt) + tuck + 6;
            const count = Math.max(1, Math.round(len / FLOW_GAP));
            const dur = len / FLOW_SPEED;
            const soft = c.type === 'note' || c.type === 'habit';
            const r = soft ? 1.5 : FLOW_R[c.depth] || 1.7;
            const phase = phaseOf(c.id);
            let dots = '';
            for (let i = 0; i < count; i += 1) {
                const begin = -((i + phase) / count) * dur;
                dots += `<circle r="${r}"><animateMotion dur="${dur.toFixed(2)}s" begin="${begin.toFixed(2)}s" repeatCount="indefinite" path="${d}"/></circle>`;
            }
            const cls = `gm-flow h${c.hue} d${Math.min(c.depth, 4)}${soft ? ' is-soft' : ''}${c.state === 'done' ? ' is-done' : ''}${lit && lit.has(c.id) ? ' is-lit' : ''}`;
            out += `<g class="${cls}" data-c="${c.id}">${dots}</g>`;
        });
        flowsG.innerHTML = out;
        if (fade) flowsG.animate([{ opacity: 0 }, { opacity: 1 }], { duration: fade, delay, easing: 'ease', fill: 'backwards' });
    }

    /* --------------------------------------------------------------- minimap */
    function drawMini() {
        const b = bounds(VIS);
        const pad = 140;
        miniSvg.setAttribute('viewBox', `${b.x0 - pad} ${b.y0 - pad} ${b.x1 - b.x0 + pad * 2} ${b.y1 - b.y0 + pad * 2}`);
        miniNodes.innerHTML = VIS.map((n) =>
            n.type === 'root'
                ? `<circle class="mm mm-root" cx="0" cy="0" r="${n.w / 2}"/>`
                : `<rect class="mm mm-${n.type} h${n.hue}" x="${n.x.toFixed(0)}" y="${(n.cy - n.h / 2).toFixed(0)}" width="${n.w}" height="${n.h}" rx="${n.h / 2.6}"/>`,
        ).join('');
        drawMiniViewport();
    }
    function drawMiniViewport() {
        miniVp.setAttribute('x', (-cam.x / cam.k).toFixed(0));
        miniVp.setAttribute('y', (-cam.y / cam.k).toFixed(0));
        miniVp.setAttribute('width', (view.clientWidth / cam.k).toFixed(0));
        miniVp.setAttribute('height', (view.clientHeight / cam.k).toFixed(0));
    }

    /* ---------------------------------------------------------------- camera */
    const cam = { x: 0, y: 0, k: 1 };
    const K_MIN = 0.18;
    const K_MAX = 2.2;
    function applyCam() {
        world.style.transform = `translate(${cam.x.toFixed(2)}px, ${cam.y.toFixed(2)}px) scale(${cam.k.toFixed(4)})`;
        let dot = 26 * cam.k;
        while (dot < 14) dot *= 2;
        while (dot > 52) dot /= 2;
        view.style.setProperty('--dot', `${dot.toFixed(2)}px`);
        view.style.setProperty('--dx', `${cam.x.toFixed(1)}px`);
        view.style.setProperty('--dy', `${cam.y.toFixed(1)}px`);
        kOut.textContent = `${Math.round(cam.k * 100)}%`;
        const lod = cam.k < 0.42 ? '0' : cam.k < 0.72 ? '1' : '2';
        if (mount.dataset.lod !== lod) mount.dataset.lod = lod;
        drawMiniViewport();
    }
    function visibleArea() {
        const vw = view.clientWidth;
        const vh = view.clientHeight;
        if (panel.hidden) return { w: vw, h: vh };
        const pr = panel.getBoundingClientRect();
        const vr = view.getBoundingClientRect();
        if (pr.width >= vr.width * 0.9) return { w: vw, h: Math.max(160, pr.top - vr.top) };
        return { w: Math.max(260, pr.left - vr.left), h: vh };
    }
    function frameFor(arr: MapNode[], pad = 56, maxK = 1.15): Frame {
        const b = bounds(arr);
        const a = visibleArea();
        const k = clamp(Math.min((a.w - pad * 2) / (b.x1 - b.x0), (a.h - pad * 2) / (b.y1 - b.y0)), K_MIN, maxK);
        return { k, cx: (b.x0 + b.x1) / 2, cy: (b.y0 + b.y1) / 2 };
    }
    let flyRaf = 0;
    function flyTo(target: Frame, ms = 650) {
        cancelAnimationFrame(flyRaf);
        const a = visibleArea();
        const from = { k: cam.k, cx: (a.w / 2 - cam.x) / cam.k, cy: (a.h / 2 - cam.y) / cam.k };
        const set = (k: number, cx: number, cy: number) => {
            cam.k = k;
            cam.x = a.w / 2 - cx * k;
            cam.y = a.h / 2 - cy * k;
            applyCam();
        };
        if (reduceMotion || !ms) {
            set(target.k, target.cx, target.cy);
            return;
        }
        const t0 = performance.now();
        const step = (now: number) => {
            const t = Math.min(1, (now - t0) / ms);
            const e = t < 0.5 ? 4 * t * t * t : 1 - (-2 * t + 2) ** 3 / 2;
            set(
                Math.exp(Math.log(from.k) + (Math.log(target.k) - Math.log(from.k)) * e),
                from.cx + (target.cx - from.cx) * e,
                from.cy + (target.cy - from.cy) * e,
            );
            if (t < 1) flyRaf = requestAnimationFrame(step);
        };
        flyRaf = requestAnimationFrame(step);
    }
    function zoomAt(px: number, py: number, factor: number) {
        cancelAnimationFrame(flyRaf);
        const k = clamp(cam.k * factor, K_MIN, K_MAX);
        const wx = (px - cam.x) / cam.k;
        const wy = (py - cam.y) / cam.k;
        cam.k = k;
        cam.x = px - wx * k;
        cam.y = py - wy * k;
        applyCam();
    }
    const fitAll = (ms?: number) => flyTo(frameFor(VIS, 48, 1), ms);
    const focusOn = (n: MapNode, ms?: number) => flyTo(frameFor(subtree(n), 80, n.type === 'task' || n.type === 'step' ? 1.25 : 1.1), ms);

    /* --------------------------------------------- relayout with animation */
    let layoutRaf = 0;
    function relayout(animate = true) {
        const before = new Map(VIS.map((n) => [n.id, { x: n.x, cy: n.cy }]));
        layout();
        syncNodes();
        const after = new Map(VIS.map((n) => [n.id, { x: n.x, cy: n.cy }]));
        if (!animate || reduceMotion) {
            positionNodes();
            drawLinks();
            drawNext();
            drawFlows();
            drawMini();
            return;
        }
        flowsG.innerHTML = '';
        after.forEach((v, id) => {
            if (before.has(id)) return;
            let p = nodes.get(id)!.parent;
            while (p && !before.has(p.id)) p = p.parent;
            const from = p ? before.get(p.id)! : v;
            before.set(id, { x: from.x, cy: from.cy });
            els.get(id)!.classList.add('is-entering');
        });
        cancelAnimationFrame(layoutRaf);
        const t0 = performance.now();
        const step = (now: number) => {
            const t = Math.min(1, (now - t0) / 420);
            const e = 1 - (1 - t) ** 3;
            after.forEach((v, id) => {
                const a = before.get(id)!;
                const n = nodes.get(id)!;
                n.x = a.x + (v.x - a.x) * e;
                n.cy = a.cy + (v.cy - a.cy) * e;
            });
            positionNodes();
            drawLinks();
            drawNext();
            if (t < 1) layoutRaf = requestAnimationFrame(step);
            else {
                els.forEach((el) => el.classList.remove('is-entering'));
                drawFlows({ fade: 400 });
                drawMini();
            }
        };
        layoutRaf = requestAnimationFrame(step);
    }

    /* --------------------------------------------------- focus and selection */
    function family(n: MapNode) {
        const s = new Set([n.id]);
        for (let p = n.parent; p; p = p.parent) s.add(p.id);
        (function down(m: MapNode) {
            (m.kids || []).forEach((c) => {
                s.add(c.id);
                down(c);
            });
        })(n);
        return s;
    }
    function setLit(set: Set<string> | null) {
        lit = set;
        mount.classList.toggle('has-lit', !!set);
        els.forEach((el, id) => el.classList.toggle('is-lit', !!set && set.has(id)));
        [linksG, flowsG].forEach((g) =>
            g.querySelectorAll<SVGElement>('[data-c]').forEach((p) => p.classList.toggle('is-lit', !!set && set.has(p.dataset.c ?? ''))),
        );
    }
    function select(n: MapNode | null | undefined, { openPanel = true } = {}) {
        selectedId = n ? n.id : null;
        els.forEach((el, id) => el.classList.toggle('is-selected', id === selectedId));
        setLit(n && n.type !== 'root' ? family(n) : null);
        mount
            .querySelectorAll<HTMLElement>('[data-gm-goal]')
            .forEach((b) => b.setAttribute('aria-pressed', String(!!n && b.dataset.gmGoal === n.id)));
        if (!n) {
            panel.hidden = true;
            return;
        }
        if (openPanel) showPanel(n);
    }
    function reveal(n: MapNode) {
        let changed = false;
        for (let p = n.parent; p; p = p.parent)
            if (!p.open) {
                p.open = true;
                changed = true;
            }
        if (n.state === 'done' && !show.done) {
            show.done = true;
            showBox('done').checked = true;
            changed = true;
        }
        if ((n.type === 'note' || n.type === 'habit') && !show.extras) {
            show.extras = true;
            showBox('extras').checked = true;
            changed = true;
        }
        if (changed) relayout(false);
    }
    function toggle(n: MapNode) {
        n.open = !n.open;
        if (!n.open && selectedId && family(n).has(selectedId) && selectedId !== n.id) select(n, { openPanel: !panel.hidden });
        relayout(true);
        if (lit) setLit(selectedId ? family(nodes.get(selectedId)!) : null);
    }

    /* ----------------------------------------------------------------- panel */
    const pfoot = (inside: string) => `<div class="panel-foot">${inside}</div>`;
    const phead = (crumbs: string) =>
        `<div class="panel-head"><div class="crumbs">${crumbs}</div><div class="panel-tools"><button class="icon-btn" data-gm-close aria-label="Close">${ic('x')}</button></div></div>`;
    const crumbsOf = (n: MapNode) => {
        const parts: string[] = [];
        for (let p = n.parent; p && p.type !== 'root'; p = p.parent) parts.unshift(esc(p.title));
        return parts.length ? parts.map((s) => `<span>${s}</span>`).join(ic('chevR')) : '<span>Goals map</span>';
    };
    const goalStyle = (n: MapNode) => (n.hue ? `style="--g:var(--goal-${n.hue})"` : '');
    function rankedPanel(n: RankedNode) {
        const t = n.task;
        return `${phead(crumbsOf(n))}<div class="panel-body" ${goalStyle(n)}>
            <div class="gp-head"><span class="rank-badge" style="width:40px;height:40px;font-size:15px;border-radius:12px">${t.rank}</span><div><h2 class="p-title">${esc(t.title)}</h2><p class="gp-why">#${t.rank} of ${data.totalRanked} · ${fmt1(t.score)} · ${esc(dueInfo(t, clock).text)} · ${fmtHours(t.estHours)}</p></div></div>
        </div>${pfoot(`<a class="btn btn-primary btn-lg" href="/tasks?task=${t.id}">${ic('arrowUpRight')}Open in Tasks</a><button class="btn btn-secondary btn-lg" data-gm-done="${t.id}">${ic('check')}Done</button>`)}`;
    }
    function showPanel(n: MapNode) {
        let html = '';
        if (isRanked(n)) html = rankedPanel(n);
        else if (n.type === 'goal') {
            const t = tally(n);
            html = `${phead('<span>Goal</span>')}<div class="panel-body" ${goalStyle(n)}>
                <div class="gp-head"><span class="gdot gdot-lg">${ic(n.goal.icon || 'target')}</span><div><h2 class="p-title">${esc(n.title)}</h2><p class="gp-why">${esc(n.goal.why || '')}</p></div></div>
                <div class="gp-stats"><span class="mn-pct">${progressRing(t.pct, 64, 6)}<span>${t.pct}%</span></span><div><b>${t.done} of ${t.total} tasks done</b><span>${n.goal.target ? `Target: ${esc(n.goal.target)}` : ''}</span></div></div>
                <h3 class="p-section-title gp-h">Projects</h3>
                <div class="gp-projects">${n.children
                    .filter((p): p is ProjectNode => p.type === 'project')
                    .map((p) => {
                        const pt = tally(p);
                        const next = p.children.find(isRanked);
                        return `<a class="gp-project" href="/projects/${p.pid}"><span class="pdot">${ic('folder')}</span><div><b>${esc(p.title)}</b><span class="gp-bar"><i style="--p:${pt.pct}%"></i></span><small>${pt.done}/${pt.total} done${next ? ` · next: #${next.rank} ${esc(next.title)}` : ''}</small></div></a>`;
                    })
                    .join('')}</div>
            </div>${pfoot(`<button class="btn btn-secondary btn-lg" data-gm-focus="${n.id}">${ic('target')}Zoom to this goal</button>${n.goal.id > 0 ? `<button class="btn btn-secondary btn-lg" data-gm-editgoal="${n.goal.id}">${ic('settings')}Edit goal</button>` : ''}`)}`;
        } else if (n.type === 'project' || n.type === 'inbox') {
            const t = tally(n);
            const open = n.children.filter(isRanked).sort((a, b) => a.rank - b.rank);
            const next = open[0];
            html = `${phead(crumbsOf(n))}<div class="panel-body" ${goalStyle(n)}>
                <div class="gp-head"><span class="pdot pdot-lg">${ic(n.type === 'inbox' ? 'inbox' : 'folder')}</span><div><h2 class="p-title">${esc(n.title)}</h2><p class="gp-why">${n.type === 'inbox' ? 'No project' : `${t.done} of ${t.total} tasks done · ${count(n, 'note')} notes · ${count(n, 'habit')} habits`}</p></div></div>
                ${next ? `<div class="why gp-next"><div class="why-label">Next · #${next.rank}</div><p class="gp-next-title">${esc(next.title)}</p></div>` : '<p class="card-empty">Nothing open here.</p>'}
                <h3 class="p-section-title gp-h">Open tasks</h3>
                <div class="gp-tasks">${open.map((x) => `<button class="gp-task" data-gm-goto="${x.id}"><span class="rank-badge">${x.rank}</span><span>${esc(x.title)}</span><b>${fmt1(x.task.score)}</b></button>`).join('') || '<p class="card-empty">None.</p>'}</div>
            </div>${pfoot(n.type === 'project' ? `<a class="btn btn-primary btn-lg" href="/projects/${n.pid}">${ic('arrowUpRight')}Open project</a><button class="btn btn-secondary btn-lg" data-gm-focus="${n.id}">${ic('target')}Zoom in</button>` : `<button class="btn btn-secondary btn-lg" data-gm-focus="${n.id}">${ic('target')}Zoom in</button>`)}`;
        } else if (n.type === 'task') {
            const t = n.task;
            html = `${phead(crumbsOf(n))}<div class="panel-body" ${goalStyle(n)}>
                <div class="gp-head"><span class="kind kind-lg">${ic(KIND_ICON[n.state] || 'info')}</span><div><h2 class="p-title">${esc(n.title)}</h2><p class="gp-why">${KIND_LABEL[n.state] ?? ''}${t.kind !== 'ranked' && t.why && n.state !== 'done' ? ` · ${esc(t.why)}` : ''}</p></div></div>
            </div>${pfoot(`<a class="btn btn-secondary btn-lg" href="/tasks?task=${t.id}">${ic('arrowUpRight')}Open in Tasks</a>`)}`;
        } else if (n.type === 'step') {
            const parent = n.parent;
            const parentRank = parent && isRanked(parent) ? parent.rank : null;
            html = `${phead(crumbsOf(n))}<div class="panel-body" ${goalStyle(n)}>
                <div class="gp-head"><span class="sdot sdot-lg${n.done ? ' is-done' : ''}">${ic('check')}</span><div><h2 class="p-title">${esc(n.title)}</h2><p class="gp-why">Step of “${esc(parent?.title)}”${parentRank ? ` (#${parentRank})` : ''}</p></div></div>
            </div>${pfoot(`<button class="btn btn-primary btn-lg" data-gm-promote="${n.stepId}">${ic('rank')}Make it a task</button>`)}`;
        } else if (n.type === 'note' || n.type === 'habit') {
            const isNote = n.type === 'note';
            html = `${phead(crumbsOf(n))}<div class="panel-body" ${goalStyle(n)}>
                <div class="gp-head"><span class="pdot pdot-lg">${ic(isNote ? 'notes' : 'habits')}</span><div><h2 class="p-title">${esc(n.title)}</h2><p class="gp-why">${isNote ? `Note${n.when ? ` · edited ${clock.date(n.when)}` : ''}` : `Habit · ${n.streak}-day streak · ${n.today ? 'done today' : 'not done yet today'}`}</p></div></div>
            </div>${pfoot(
                (n.type === 'note'
                    ? `<a class="btn btn-primary btn-lg" href="/notes?in=p${n.pid}&amp;note=${n.noteId}">${ic('arrowUpRight')}Open note</a>`
                    : `<a class="btn btn-primary btn-lg" href="/habits?habit=${n.habitId}">${ic('arrowUpRight')}Open habit</a>`) +
                    `<a class="btn btn-secondary btn-lg" href="/projects/${n.pid}">${ic('folder')}Open project</a>`,
            )}`;
        } else {
            html = `${phead('<span>You</span>')}<div class="panel-body">
                <div class="gp-head"><span class="avatar avatar-lg">${esc(initials)}</span><div><h2 class="p-title">${esc(data.userName)}</h2><p class="gp-why">${goalCount} goals · ${projectCount} projects · ${totals.total} tasks</p></div></div>
                <div class="gp-stats"><span class="mn-pct">${progressRing(totals.pct, 64, 6)}<span>${totals.pct}%</span></span><div><b>${totals.done} of ${totals.total} tasks done</b><span>${NEXT ? `Your next task is #1: ${esc(NEXT.title)}` : ''}</span></div></div>
                <div class="gp-projects">${data.goals
                    .map((g) => {
                        const gt = tally(goalNode(g));
                        return `<button class="gp-project" data-gm-goto="g${g.id}" style="--g:var(--goal-${g.color})"><span class="gdot">${ic(g.icon || 'target')}</span><div><b>${esc(g.title)}</b><span class="gp-bar"><i style="--p:${gt.pct}%"></i></span><small>${gt.done}/${gt.total} done${g.target ? ` · by ${esc(g.target)}` : ''}</small></div></button>`;
                    })
                    .join('')}</div>
            </div>${NEXT ? pfoot(`<button class="btn btn-primary btn-lg" data-gm-goto="${NEXT.id}">${ic('zap')}Go to your next task</button>`) : ''}`;
        }
        panel.innerHTML = html;
        panel.hidden = false;
    }

    /* --------------------------------------------------------------- outline */
    function renderOutline() {
        const rows: string[] = [];
        (function walk(n: MapNode, lv: number) {
            if (n.type !== 'root') {
                if ((n.type === 'note' || n.type === 'habit') && !show.extras) return;
                if (n.state === 'done' && !show.done) return;
                let lead = '';
                let meta = '';
                if (n.type === 'goal') {
                    lead = `<span class="gdot">${ic(n.goal.icon || 'target')}</span>`;
                    meta = `${tally(n).pct}% done${n.goal.target ? ` · by ${esc(n.goal.target)}` : ''}`;
                } else if (n.type === 'project' || n.type === 'inbox') {
                    const t = tally(n);
                    lead = `<span class="pdot">${ic(n.type === 'inbox' ? 'inbox' : 'folder')}</span>`;
                    meta = `${t.done}/${t.total} tasks done`;
                } else if (isRanked(n)) {
                    lead = `<span class="rank-badge">${n.rank}</span>`;
                    meta = `${dueInfo(n.task, clock).text} · priority ${fmt1(n.task.score)}`;
                } else if (n.type === 'task') {
                    lead = `<span class="kind">${ic(KIND_ICON[n.state] || 'info')}</span>`;
                    meta = KIND_LABEL[n.state] ?? '';
                } else if (n.type === 'step') {
                    lead = `<span class="sdot${n.done ? ' is-done' : ''}">${ic('check')}</span>`;
                    meta = n.done ? 'Done' : 'Step';
                } else {
                    lead = ic(n.type === 'note' ? 'notes' : 'habits');
                    meta = n.type === 'note' ? 'Note' : `Habit · ${n.streak}-day streak`;
                }
                rows.push(
                    `<button class="go-row go-${n.type} h${n.hue}${n === NEXT ? ' is-next' : ''}${n.state === 'done' ? ' is-done' : ''}" data-go="${n.id}" style="--lv:${lv - 1}" role="treeitem" aria-level="${lv}">${lead}<span class="go-title">${esc(n.title)}</span><span class="go-meta">${meta}</span></button>`,
                );
            }
            n.children.forEach((c) => walk(c, lv + 1));
        })(root, 0);
        outline.innerHTML = `<div class="go-wrap"><p class="go-intro">The same map as a list, in the same order. Click any line to find it on the map.</p><div class="go-list" role="tree" aria-label="Goals, projects, tasks and steps">${rows.join('')}</div></div>`;
    }
    function setMode(mode: string | undefined) {
        mount.querySelectorAll<HTMLElement>('[data-gm-mode]').forEach((b) => b.setAttribute('aria-pressed', String(b.dataset.gmMode === mode)));
        const isMap = mode === 'map';
        $('.gm-stage').hidden = !isMap;
        outline.hidden = isMap;
        if (!isMap) renderOutline();
        else {
            applyCam();
            drawMini();
        }
    }

    /* ---------------------------------------------------------------- events */
    const pointers = new Map<number, { x: number; y: number }>();
    let pan = null as { x: number; y: number; cx: number; cy: number } | null;
    let pinch = null as { d: number; k: number; mx: number; my: number; x: number; y: number } | null;
    let dragged = false;
    // The element an event happened on (events here always come from elements).
    const at = (e: Event) => e.target as Element;
    const hideHint = () => mount.querySelector('[data-gm-hint]')?.classList.add('is-gone');
    on(view, 'pointerdown', (e) => {
        if (e.button !== 0 && e.pointerType === 'mouse') return;
        pointers.set(e.pointerId, { x: e.clientX, y: e.clientY });
        if (pointers.size === 1) {
            pan = { x: e.clientX, y: e.clientY, cx: cam.x, cy: cam.y };
            dragged = false;
        }
        if (pointers.size === 2) {
            const [a, b] = [...pointers.values()];
            const r = view.getBoundingClientRect();
            pinch = { d: Math.hypot(a.x - b.x, a.y - b.y), k: cam.k, mx: (a.x + b.x) / 2 - r.left, my: (a.y + b.y) / 2 - r.top, x: cam.x, y: cam.y };
            pan = null;
        }
    });
    on(view, 'pointermove', (e) => {
        if (!pointers.has(e.pointerId)) return;
        pointers.set(e.pointerId, { x: e.clientX, y: e.clientY });
        if (pinch && pointers.size === 2) {
            const [a, b] = [...pointers.values()];
            const k = clamp(pinch.k * (Math.hypot(a.x - b.x, a.y - b.y) / pinch.d), K_MIN, K_MAX);
            const wx = (pinch.mx - pinch.x) / pinch.k;
            const wy = (pinch.my - pinch.y) / pinch.k;
            cam.k = k;
            cam.x = pinch.mx - wx * k;
            cam.y = pinch.my - wy * k;
            applyCam();
            dragged = true;
            return;
        }
        if (!pan) return;
        const dx = e.clientX - pan.x;
        const dy = e.clientY - pan.y;
        if (!dragged && Math.hypot(dx, dy) > 4) {
            dragged = true;
            view.setPointerCapture(e.pointerId);
            view.classList.add('is-panning');
            cancelAnimationFrame(flyRaf);
            hideHint();
        }
        if (dragged) {
            cam.x = pan.cx + dx;
            cam.y = pan.cy + dy;
            applyCam();
        }
    });
    const endPointer = (e: PointerEvent) => {
        pointers.delete(e.pointerId);
        if (pointers.size < 2) pinch = null;
        if (!pointers.size) {
            pan = null;
            view.classList.remove('is-panning');
        }
    };
    on(view, 'pointerup', endPointer);
    on(view, 'pointercancel', endPointer);
    on(
        view,
        'wheel',
        (e) => {
            e.preventDefault();
            hideHint();
            const r = view.getBoundingClientRect();
            const trackpadScroll = !e.ctrlKey && (Math.abs(e.deltaX) > 0.5 || (e.deltaMode === 0 && !Number.isInteger(e.deltaY)));
            if (trackpadScroll) {
                cancelAnimationFrame(flyRaf);
                cam.x -= e.deltaX;
                cam.y -= e.deltaY;
                applyCam();
                return;
            }
            zoomAt(e.clientX - r.left, e.clientY - r.top, Math.exp(-e.deltaY * (e.ctrlKey ? 0.012 : 0.0018) * (e.deltaMode === 1 ? 16 : 1)));
        },
        { passive: false },
    );
    on(nodesEl, 'click', (e) => {
        if (dragged) {
            dragged = false;
            return;
        }
        const k = at(e).closest<HTMLElement>('[data-knob]');
        if (k) {
            e.stopPropagation();
            const n = nodeOf(k.dataset.knob);
            if (n) toggle(n);
            return;
        }
        const el = at(e).closest<HTMLElement>('.mn');
        if (el) select(nodeOf(el.dataset.id));
    });
    on(nodesEl, 'dblclick', (e) => {
        const n = nodeOf(at(e).closest<HTMLElement>('.mn')?.dataset.id);
        if (n && !at(e).closest('[data-knob]')) focusOn(n);
    });
    on(view, 'click', (e) => {
        if (dragged) {
            dragged = false;
            return;
        }
        if (!at(e).closest('.mn')) select(null);
    });
    on(nodesEl, 'pointerover', (e) => {
        if (pan && dragged) return;
        const id = at(e).closest<HTMLElement>('.mn')?.dataset.id;
        const n = id !== 'root' ? nodeOf(id) : undefined;
        if (n) setLit(family(n));
    });
    on(nodesEl, 'pointerout', (e) => {
        const to = e.relatedTarget instanceof Element ? e.relatedTarget.closest('.mn') : null;
        if (to) return;
        setLit(selectedId && selectedId !== 'root' ? family(nodes.get(selectedId)!) : null);
    });
    function goNext() {
        if (!NEXT) return;
        reveal(NEXT);
        select(NEXT);
        focusOn(NEXT);
    }
    function goTo(id: string) {
        const n = nodes.get(id);
        if (!n) return;
        if ($('.gm-stage').hidden) setMode('map');
        reveal(n);
        select(n);
        focusOn(n);
        const el = els.get(id);
        if (el) {
            el.classList.remove('is-pulse');
            void el.offsetWidth;
            el.classList.add('is-pulse');
        }
    }
    on(view, 'keydown', (e) => {
        const c = { x: view.clientWidth / 2, y: view.clientHeight / 2 };
        const keys: Record<string, () => void> = {
            '+': () => zoomAt(c.x, c.y, 1.25),
            '=': () => zoomAt(c.x, c.y, 1.25),
            '-': () => zoomAt(c.x, c.y, 0.8),
            0: () => fitAll(),
            n: goNext,
            N: goNext,
            ArrowLeft: () => {
                cam.x += 80;
                applyCam();
            },
            ArrowRight: () => {
                cam.x -= 80;
                applyCam();
            },
            ArrowUp: () => {
                cam.y += 80;
                applyCam();
            },
            ArrowDown: () => {
                cam.y -= 80;
                applyCam();
            },
            Escape: () => select(null),
        };
        if (keys[e.key]) {
            e.preventDefault();
            keys[e.key]();
            hideHint();
        }
    });
    const mini = $('.gm-mini');
    let miniDrag = false;
    const miniMove = (e: PointerEvent) => {
        const ctm = miniSvg.getScreenCTM();
        if (!ctm) return;
        const pt = miniSvg.createSVGPoint();
        pt.x = e.clientX;
        pt.y = e.clientY;
        const w = pt.matrixTransform(ctm.inverse());
        cancelAnimationFrame(flyRaf);
        cam.x = view.clientWidth / 2 - w.x * cam.k;
        cam.y = view.clientHeight / 2 - w.y * cam.k;
        applyCam();
    };
    on(mini, 'pointerdown', (e) => {
        miniDrag = true;
        mini.setPointerCapture(e.pointerId);
        miniMove(e);
        hideHint();
    });
    on(mini, 'pointermove', (e) => {
        if (miniDrag) miniMove(e);
    });
    on(mini, 'pointerup', () => {
        miniDrag = false;
    });
    on(mount, 'click', (e) => {
        const t = at(e);
        const z = t.closest<HTMLElement>('[data-gm-zoom]');
        if (z) return zoomAt(view.clientWidth / 2, view.clientHeight / 2, z.dataset.gmZoom === 'in' ? 1.25 : 0.8);
        if (t.closest('[data-gm-fit]')) {
            select(null);
            return fitAll();
        }
        if (t.closest('[data-gm-gonext]')) return goNext();
        if (t.closest('[data-gm-close]')) return select(null);
        if (t.closest('[data-gm-side]')) return ctx.onToggleMenu();
        if (t.closest('[data-gm-newgoal]')) return ctx.onNewGoal();
        const editGoal = t.closest<HTMLElement>('[data-gm-editgoal]');
        if (editGoal) return ctx.onEditGoal(Number(editGoal.dataset.gmEditgoal));
        const goal = t.closest<HTMLElement>('[data-gm-goal]');
        if (goal) {
            const n = nodeOf(goal.dataset.gmGoal);
            if (n) {
                reveal(n);
                select(n);
                focusOn(n);
            }
            return;
        }
        const focus = t.closest<HTMLElement>('[data-gm-focus]');
        if (focus) {
            const n = nodeOf(focus.dataset.gmFocus);
            if (n) focusOn(n);
            return;
        }
        const go = t.closest<HTMLElement>('[data-gm-goto], [data-go]');
        if (go) return goTo(go.dataset.gmGoto || go.dataset.go || '');
        const done = t.closest<HTMLElement>('[data-gm-done]');
        if (done) return ctx.onDone(Number(done.dataset.gmDone));
        const promote = t.closest<HTMLElement>('[data-gm-promote]');
        if (promote) return ctx.onPromote(Number(promote.dataset.gmPromote));
        const mode = t.closest<HTMLElement>('[data-gm-mode]');
        if (mode) return setMode(mode.dataset.gmMode);
        const layers = t.closest('[data-gm-layers]');
        if (layers) {
            const pop = $('#gm-layers');
            pop.hidden = !pop.hidden;
            layers.setAttribute('aria-expanded', String(!pop.hidden));
            return;
        }
        if (t.closest('[data-gm-unfold]')) {
            nodes.forEach((n) => {
                n.open = true;
            });
            relayout(true);
            setTimeout(() => fitAll(), 440);
            return;
        }
        if (t.closest('[data-gm-fold]')) {
            nodes.forEach((n) => {
                n.open = n.type === 'root' || n.type === 'goal' || n.type === 'inbox';
            });
            relayout(true);
            setTimeout(() => fitAll(), 440);
        }
    });
    on(mount, 'change', (e) => {
        const box = e.target as HTMLInputElement;
        const key = box.dataset.gmShow as keyof typeof show | undefined;
        if (!key) return;
        show[key] = box.checked;
        if (key === 'path') {
            drawNext();
            drawFlows();
        } else {
            relayout(true);
            if (!outline.hidden) renderOutline();
        }
    });
    on(document, 'click', (e) => {
        const pop = $('#gm-layers');
        if (pop && !pop.hidden && !at(e).closest('.pop-wrap')) {
            pop.hidden = true;
            $('[data-gm-layers]').setAttribute('aria-expanded', 'false');
        }
    });
    let matches: MapNode[] = [];
    let matchAt = -1;
    on(searchInput, 'input', () => {
        const q = searchInput.value.trim().toLowerCase();
        matches = q.length < 2 ? [] : [...nodes.values()].filter((n) => n.type !== 'root' && n.title.toLowerCase().includes(q));
        matchAt = -1;
        searchCount.textContent = q.length < 2 ? '' : matches.length ? `${matches.length} found` : 'None';
    });
    on(searchInput, 'keydown', (e) => {
        if (e.key === 'Enter' && matches.length) {
            e.preventDefault();
            matchAt = (matchAt + 1) % matches.length;
            goTo(matches[matchAt].id);
            searchCount.textContent = `${matchAt + 1} of ${matches.length}`;
        }
        if (e.key === 'Escape') {
            searchInput.value = '';
            searchInput.dispatchEvent(new Event('input'));
            view.focus();
        }
    });
    on(document, 'keydown', (e) => {
        if (e.key === '/' && !at(e).closest('input, textarea, select, [contenteditable="true"]')) {
            e.preventDefault();
            searchInput.focus();
        }
    });
    const ro = new ResizeObserver(() => drawMiniViewport());
    ro.observe(view);
    cleanups.push(() => ro.disconnect());

    /* ----------------------------------------------------------------- start */
    layout();
    syncNodes();
    positionNodes();
    drawLinks();
    drawNext();
    drawFlows({ fade: 700, delay: 700 });
    drawMini();
    if (view.clientWidth < 600) flyTo({ k: 0.66, cx: 0, cy: 0 }, 0);
    else fitAll(0);
    if (!reduceMotion) {
        mount.classList.add('is-intro');
        const t = setTimeout(() => mount.classList.remove('is-intro'), 1800);
        cleanups.push(() => clearTimeout(t));
    }
    const focusId = data.focus;
    if (focusId && nodes.has(focusId)) {
        const t = setTimeout(() => goTo(focusId), reduceMotion ? 0 : 900);
        cleanups.push(() => clearTimeout(t));
    }

    return () => {
        cancelAnimationFrame(flyRaf);
        cancelAnimationFrame(layoutRaf);
        cleanups.forEach((fn) => fn());
        mount.innerHTML = '';
    };
}
