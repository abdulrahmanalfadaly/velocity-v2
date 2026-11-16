import { useEffect, useState } from 'react';

/**
 * For a popover or dialog: when it closes, the keyboard goes back to what opened it
 * (the button you pressed). Only when the keyboard was left nowhere, so a click
 * somewhere else keeps its own focus.
 */
export function useReturnFocus() {
    // Read while drawing for the first time: before anything inside takes the focus.
    const [opener] = useState(() =>
        typeof document !== 'undefined' && document.activeElement instanceof HTMLElement && document.activeElement !== document.body
            ? document.activeElement
            : null,
    );
    useEffect(
        () => () => {
            if (!opener) return;
            // Once the popover is gone from the page.
            setTimeout(() => {
                const now = document.activeElement;
                if (opener.isConnected && (!now || now === document.body)) opener.focus({ preventScroll: true });
            });
        },
        [opener],
    );
}
