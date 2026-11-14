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
export type RepeatUnit = 'day' | 'weekday' | 'week' | 'month' | 'year';
export interface RepeatRule {
    unit: RepeatUnit;
    every: number;
}

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
    /** A day to check on it ("YYYY-MM-DD"); never changes the order. */
    followUpOn: string | null;
    waitingSince: string | null;
    cancelledAt: string | null;
    cancelReason: string | null;
    archivedAt: string | null;
    repeat: RepeatRule | null;
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

/** A task whose follow-up day has come: "Follow up today · waiting for Dr. Lim, 5 days". */
export interface FollowUpTask extends BaseTask {
    rank: number | null;
    why: string;
}

export type TaskDetail = BaseTask &
    Partial<Omit<RankedTask, keyof BaseTask>> & {
        total: number;
        children: { id: number; title: string; rank: number | null; score: number | null }[];
        dependsOn: { id: number; title: string; status: Status }[];
        events: { type: string; at: string | null; via?: string | null }[];
        candidates: { id: number; title: string }[];
        files: TaskFileInfo[];
        reminders: TaskReminderInfo[];
        /** Notes linked to the task (Notes › Link task, or New note here). */
        linkedNotes?: { id: number; title: string }[];
        /** People the task is about or waiting on. */
        people?: { id: number; name: string }[];
        peopleChoices?: { id: number; name: string }[];
        focused: { seconds: number; sessions: number };
        goal: { id: number; title: string; color: number } | null;
        /** Linked GitHub issues and pull requests; null when GitHub isn't connected and nothing is linked. */
        github?: {
            connected: boolean;
            repo: string | null;
            links: {
                id: number;
                repo: string;
                number: number;
                kind: 'issue' | 'pull';
                title: string;
                state: 'open' | 'closed' | 'merged' | 'draft';
                url: string;
                fetchedAt: string | null;
            }[];
            stale: boolean;
        } | null;
    };

/** One reminder: at a time, before the due date (moves with it), or "Bug me" every so often. */
export interface TaskReminderInfo {
    id: number;
    kind: 'at' | 'before' | 'bug';
    at: string | null;
    minutesBefore: number | null;
    daysBefore: number | null;
    atTime: string | null;
    everyMinutes: number | null;
    nextAt: string | null;
}

export interface TaskFileInfo {
    id: number;
    name: string;
    mime: string;
    size: number;
    image: boolean;
    url: string;
    addedAt: string | null;
}

export interface RankingSettings {
    weights: Record<CriterionKey, number>;
    isDefault: boolean;
    horizonDays: number;
    hoursPerDay: number;
}

export interface HabitToday {
    id: number;
    name: string;
    /** do: reach the target; minutes: target minutes; limit: at most the target; avoid: none at all. */
    kind: 'do' | 'minutes' | 'limit' | 'avoid';
    detail: string | null;
    target: number;
    unit: string | null;
    /** Chosen weekdays (1 = Monday; empty = every day), times a week or a month on any days, or every N days. */
    schedule: { days: number[]; perWeek: number | null; perMonth: number | null; everyDays: number | null };
    /** "Every day", "Mon, Wed, Fri", "3 times a week" */
    label: string;
    today: number;
    doneToday: boolean;
    /** A limit gone over, or the thing to avoid happened, today. */
    overToday: boolean;
    skippedToday: boolean;
    dueToday: boolean;
    /** Weekly habits: runs so far this week, and how many the week asks for. */
    week: { done: number; need: number } | null;
    /** Monthly habits: runs so far this month, and how many it asks for. */
    month: { done: number; need: number } | null;
    group: 'todo' | 'done' | 'skipped' | 'off' | 'paused';
    pausedSince: string | null;
    pausedUntil: string | null;
    streak: number;
    streakUnit: 'day' | 'week' | 'month';
    time: string | null;
    minutes: number | null;
    project: { id: number; name: string } | null;
    archived: boolean;
}

export type HabitDayState = 'done' | 'partial' | 'skipped' | 'paused' | 'missed' | 'todo' | 'open' | 'off' | 'future' | 'before';

export interface HabitDay {
    date: string;
    state: HabitDayState;
    count: number;
    /** A note on that day's check-in. */
    note?: string | null;
}

export interface NavProject {
    id: number;
    name: string;
    goalColor: number | null;
    open: number;
}

export interface NavData {
    counts: { upNext: number; inbox: number; waiting: number; goals: number };
    areas: { id: number; name: string; icon: string | null; projects: NavProject[] }[];
    /** Projects that are not in any area. */
    loose: NavProject[];
    goals: { id: number; title: string; color: number }[];
}

export interface VelocityShared {
    auth: { user: { id: number; name: string; email: string; timezone: string; fypMode: boolean } | null };
    clock: { now: string; tz: string; frozen?: boolean };
    nav: NavData | null;
    /** `undo.action` names a route in toast.tsx; `data` is sent with the request. */
    flash: {
        toast: string | null;
        undo: { action: string; id: number; data?: Record<string, unknown> | null } | null;
        /** After stopping focus on a task: asks whether to update its hours left. */
        focusDone?: { sessionId: number; taskId: number; title: string; seconds: number; estHours: number; hoursLeft: number } | null;
    };
    /** The focus session that is running, if any (personal mode only). */
    focusSession?: FocusRunning | null;
    [key: string]: unknown;
}

/** The timer: a running session (focus), or a Pomodoro between rounds (its last round, with the break). */
export interface FocusRunning {
    id: number;
    taskId: number | null;
    taskTitle: string | null;
    taskOpen: boolean;
    mode: 'timer' | 'pomodoro';
    round: number | null;
    intention: string | null;
    plannedMinutes: number | null;
    startedAt: string;
    pausedAt: string | null;
    pausedSeconds: number;
    breakMinutes: number | null;
    breakEndsAt: string | null;
    phase: 'focus' | 'roundDone' | 'break' | 'breakOver';
    sound: boolean;
    every: number;
}
