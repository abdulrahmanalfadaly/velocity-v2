export type CriterionKey = 'I' | 'U' | 'D' | 'P';
export type Status = 'todo' | 'doing' | 'waiting' | 'done' | 'cancelled';

export interface ProjectRef {
    id: number;
    name: string;
    goalColor: number | null;
    goalTitle?: string | null;
}

export interface Step {
    id: number;
    title: string;
    done: boolean;
}

/** Any task, ranked or not. Dates are UTC ISO strings. */
export interface BaseTask {
    id: number;
    title: string;
    notes: string | null;
    status: Status;
    importance: number;
    urgency: number;
    dueAt: string | null;
    estHours: number;
    waitingFor: string | null;
    availableFrom: string | null;
    completedAt: string | null;
    scoreAtCompletion: number | null;
    project: ProjectRef | null;
    parent: { id: number; title: string } | null;
    steps: Step[];
}

/** A task with its place in the ranking (report Algorithms 3.2 and 3.3). */
export interface RankedTask extends BaseTask {
    rank: number;
    score: number;
    contrib: Record<CriterionKey, number>;
    criteria: Record<CriterionKey, number>;
    days: number | null;
    summary: string;
    contrast: string | null;
    deltas: Record<CriterionKey, number> | null;
    gap: number | null;
    below: { id: number; title: string } | null;
    nearTie: boolean;
}

export interface NotReadyTask extends BaseTask {
    kind: 'waiting' | 'blocked' | 'later';
    why: string;
}

export type TaskDetail = BaseTask &
    Partial<Omit<RankedTask, keyof BaseTask>> & {
        total: number;
        children: { id: number; title: string; rank: number | null; score: number | null }[];
        dependsOn: { id: number; title: string; status: Status }[];
        events: { type: string; at: string | null }[];
    };

export interface RankingSettings {
    weights: Record<CriterionKey, number>;
    isDefault: boolean;
    horizonDays: number;
    hoursPerDay: number;
}

export interface HabitToday {
    id: number;
    name: string;
    detail: string | null;
    target: number;
    today: number;
    doneToday: boolean;
    dueToday: boolean;
    streak: number;
    project: { id: number; name: string } | null;
}

export interface NavData {
    counts: { upNext: number; inbox: number; waiting: number; goals: number };
    areas: { id: number; name: string; icon: string | null; projects: { id: number; name: string; goalColor: number | null; open: number }[] }[];
}

export interface VelocityShared {
    auth: { user: { id: number; name: string; email: string; timezone: string; fypMode: boolean } | null };
    clock: { now: string; tz: string; frozen?: boolean };
    nav: NavData | null;
    flash: { toast: string | null; undo: { action: 'reopen' | 'restore'; id: number } | null };
    [key: string]: unknown;
}
