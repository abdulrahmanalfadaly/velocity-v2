import type { CriterionKey } from '@/types/velocity';

/** The report's names for the four criteria (Table 3.2). */
export const CRITERION: Record<CriterionKey, string> = {
    I: 'Importance',
    U: 'Urgency',
    D: 'Deadline proximity',
    P: 'Workload pressure',
};

export const IMPORTANCE_LABELS = ['', 'Minimal', 'Low', 'Moderate', 'High', 'Critical'];
export const URGENCY_LABELS = ['', 'Can wait', 'Low', 'Moderate', 'Pressing', 'Immediate'];

/** "8.5" rather than "8.50"; "7" rather than "7.0". */
export const trim = (v: number, dp = 1) => String(Number(v.toFixed(dp)));
