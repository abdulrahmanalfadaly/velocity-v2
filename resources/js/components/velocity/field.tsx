import { type InputHTMLAttributes, type ReactNode, type Ref } from 'react';

type TextFieldProps = Omit<InputHTMLAttributes<HTMLInputElement>, 'id' | 'value' | 'onChange'> & {
    id: string;
    label: string;
    value: string;
    onValue: (value: string) => void;
    error?: string;
    inputRef?: Ref<HTMLInputElement>;
};

/** A Field holding one text input. */
export function TextField({ id, label, value, onValue, error, inputRef, ...rest }: TextFieldProps) {
    return (
        <Field id={id} label={label} error={error}>
            <input
                id={id}
                ref={inputRef}
                className="input"
                value={value}
                onChange={(e) => onValue(e.target.value)}
                aria-describedby={error ? `${id}-error` : undefined}
                {...rest}
            />
        </Field>
    );
}

/** A labelled input with its error underneath. The input should point at `${id}-error` with aria-describedby. */
export function Field({ id, label, error, extra, children }: { id: string; label: string; error?: string; extra?: ReactNode; children: ReactNode }) {
    return (
        <div className={`field${error ? ' is-invalid' : ''}`}>
            <div className="field-label">
                <label htmlFor={id}>{label}</label>
                {extra}
            </div>
            {children}
            {error && (
                <p className="field-error" id={`${id}-error`}>
                    {error}
                </p>
            )}
        </div>
    );
}
