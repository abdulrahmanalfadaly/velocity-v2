import { LEVEL } from '@/lib/velocity';
import { DatePick } from './date-pick';
import { Select } from './select';

type Extra = Record<string, string | number | null>;

/** The selection bar's "Set…" (importance, urgency or status) and "Follow up…" for every selected task. */
export function BulkSet({ disabled, onSet }: { disabled?: boolean; onSet: (action: string, extra: Extra) => void }) {
    const levels = (key: 'importance' | 'urgency', group: string) => [5, 4, 3, 2, 1].map((n) => ({ value: `${key}:${n}`, label: LEVEL[n], group }));
    return (
        <>
            <Select
                className="prop-input"
                label="Set importance, urgency or status"
                value=""
                placeholder="Set…"
                disabled={disabled}
                onChange={(v) => {
                    const [key, value] = v.split(':');
                    onSet(key, { [key]: key === 'status' ? value : Number(value) });
                }}
                options={[
                    ...levels('importance', 'Importance'),
                    ...levels('urgency', 'Urgency'),
                    ...[
                        ['todo', 'To do'],
                        ['doing', 'Doing'],
                        ['waiting', 'Waiting'],
                    ].map(([s, label]) => ({ value: `status:${s}`, label, group: 'Status' })),
                ]}
            />
            <DatePick
                className="prop-input"
                label="Follow-up date for the selected tasks"
                placeholder="Follow up…"
                value=""
                onChange={(v) => !disabled && onSet('follow_up', { follow_up_on: v || null })}
            />
        </>
    );
}
