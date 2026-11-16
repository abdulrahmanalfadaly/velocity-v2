import { mergeAttributes, Node } from '@tiptap/core';
import { type NodeViewProps, NodeViewWrapper, ReactNodeViewRenderer } from '@tiptap/react';
import { type PointerEvent as ReactPointerEvent, useEffect, useRef, useState } from 'react';
import { Icon } from './icon';

/*
 * Drawing in a note: a box inside the text where you draw with a pen or add lines,
 * arrows, rectangles and circles. No separate mode: click the box to draw, click
 * outside it to keep typing. The shapes are saved in the note itself.
 */

type Tool = 'pen' | 'line' | 'arrow' | 'rect' | 'ellipse' | 'erase';
type Colour = 'ink' | 'grey' | 'violet';
/** One thing drawn: a pen stroke (points x, y, x, y …) or a shape (two corners). */
interface Shape {
    t: Exclude<Tool, 'erase'>;
    c: Colour;
    w: number;
    p: number[];
}

const W = 800; // the drawing's own width; it scales to the note's width
const MIN_H = 120;
const MAX_H = 1600;
const COLOURS: [Colour, string, string][] = [
    ['ink', 'var(--text)', 'Ink'],
    ['grey', 'var(--text-3)', 'Grey'],
    ['violet', 'var(--violet)', 'Violet'],
];
const TOOLS: [Tool, string, string][] = [
    ['pen', 'pencil', 'Pen'],
    ['line', 'slash', 'Line'],
    ['arrow', 'arrowLine', 'Arrow'],
    ['rect', 'stop', 'Rectangle'],
    ['ellipse', 'circle', 'Circle'],
    ['erase', 'eraser', 'Eraser (click a shape)'],
];
const colourOf = (c: Colour) => COLOURS.find((x) => x[0] === c)?.[1] ?? 'var(--text)';

/** The shapes as stored, cleaned of anything that isn't one. */
function shapesOf(v: unknown): Shape[] {
    return Array.isArray(v) ? v.filter((s): s is Shape => !!s && typeof s === 'object' && Array.isArray((s as Shape).p)) : [];
}

function draw(s: Shape, key: string | number) {
    const common = {
        stroke: colourOf(s.c),
        strokeWidth: s.w,
        fill: 'none',
        strokeLinecap: 'round' as const,
        strokeLinejoin: 'round' as const,
    };
    const [x1, y1, x2, y2] = s.p;
    switch (s.t) {
        case 'pen': {
            const pts: string[] = [];
            for (let i = 0; i + 1 < s.p.length; i += 2) pts.push(`${s.p[i]} ${s.p[i + 1]}`);
            return <path key={key} d={`M${pts.join(' L')}${pts.length === 1 ? ` L${pts[0]}` : ''}`} {...common} />;
        }
        case 'line':
            return <line key={key} x1={x1} y1={y1} x2={x2} y2={y2} {...common} />;
        case 'arrow': {
            const a = Math.atan2(y2 - y1, x2 - x1);
            const len = 12 + s.w * 2;
            const h = (d: number) => `${x2 - len * Math.cos(a + d)} ${y2 - len * Math.sin(a + d)}`;
            return <path key={key} d={`M${x1} ${y1} L${x2} ${y2} M${h(-0.45)} L${x2} ${y2} L${h(0.45)}`} {...common} />;
        }
        case 'rect':
            return (
                <rect key={key} x={Math.min(x1, x2)} y={Math.min(y1, y2)} width={Math.abs(x2 - x1)} height={Math.abs(y2 - y1)} rx={6} {...common} />
            );
        default:
            return <ellipse key={key} cx={(x1 + x2) / 2} cy={(y1 + y2) / 2} rx={Math.abs(x2 - x1) / 2} ry={Math.abs(y2 - y1) / 2} {...common} />;
    }
}

/** The shape under a point (the one on top first), for the eraser; -1 for none. */
function hit(shapes: Shape[], x: number, y: number): number {
    const R = 10;
    const nearSegment = (ax: number, ay: number, bx: number, by: number) => {
        const dx = bx - ax;
        const dy = by - ay;
        const t = dx || dy ? Math.max(0, Math.min(1, ((x - ax) * dx + (y - ay) * dy) / (dx * dx + dy * dy))) : 0;
        return Math.hypot(x - (ax + t * dx), y - (ay + t * dy)) <= R;
    };
    for (let i = shapes.length - 1; i >= 0; i--) {
        const s = shapes[i];
        const [x1, y1, x2, y2] = s.p;
        if (s.t === 'pen') {
            for (let j = 0; j + 1 < s.p.length; j += 2) {
                const nx = s.p[j + 2] ?? s.p[j];
                const ny = s.p[j + 3] ?? s.p[j + 1];
                if (nearSegment(s.p[j], s.p[j + 1], nx, ny)) return i;
            }
        } else if (s.t === 'line' || s.t === 'arrow') {
            if (nearSegment(x1, y1, x2, y2)) return i;
        } else if (x >= Math.min(x1, x2) - R && x <= Math.max(x1, x2) + R && y >= Math.min(y1, y2) - R && y <= Math.max(y1, y2) + R) {
            return i;
        }
    }
    return -1;
}

function DrawingView({ node, updateAttributes, editor }: NodeViewProps) {
    const shapes = shapesOf(node.attrs.strokes);
    const saved = Math.max(MIN_H, Math.min(MAX_H, Number(node.attrs.height) || 300));
    const [height, setHeight] = useState(saved);
    useEffect(() => setHeight(saved), [saved]);
    const [active, setActive] = useState(false);
    const [tool, setTool] = useState<Tool>('pen');
    const [colour, setColour] = useState<Colour>('ink');
    const [thick, setThick] = useState(false);
    const [draft, setDraft] = useState<Shape | null>(null);
    const svg = useRef<SVGSVGElement>(null);
    const wrap = useRef<HTMLDivElement>(null);
    const editable = editor.isEditable;

    // A click anywhere outside the drawing: back to typing.
    useEffect(() => {
        if (!active) return;
        const away = (e: PointerEvent) => !wrap.current?.contains(e.target as globalThis.Node) && setActive(false);
        const esc = (e: KeyboardEvent) => e.key === 'Escape' && setActive(false);
        document.addEventListener('pointerdown', away, true);
        document.addEventListener('keydown', esc);
        return () => {
            document.removeEventListener('pointerdown', away, true);
            document.removeEventListener('keydown', esc);
        };
    }, [active]);

    const save = (next: Shape[]) => updateAttributes({ strokes: next });
    const point = (e: ReactPointerEvent) => {
        const m = svg.current?.getScreenCTM();
        if (!m) return [0, 0];
        const p = new DOMPoint(e.clientX, e.clientY).matrixTransform(m.inverse());
        return [Math.round(p.x), Math.round(p.y)];
    };

    const down = (e: ReactPointerEvent<SVGSVGElement>) => {
        if (!editor.isEditable || e.button !== 0) return; // checked now: the note may have been archived since
        if (!active) {
            setActive(true);
            return;
        }
        e.preventDefault();
        const [x, y] = point(e);
        if (tool === 'erase') {
            const i = hit(shapes, x, y);
            if (i >= 0) save(shapes.filter((_, j) => j !== i));
            return;
        }
        svg.current?.setPointerCapture(e.pointerId);
        setDraft({ t: tool, c: colour, w: thick ? 6 : 3, p: tool === 'pen' ? [x, y] : [x, y, x, y] });
    };
    const move = (e: ReactPointerEvent<SVGSVGElement>) => {
        if (!draft) return;
        const [x, y] = point(e);
        setDraft((d) => {
            if (!d) return d;
            if (d.t !== 'pen') return { ...d, p: [d.p[0], d.p[1], x, y] };
            const n = d.p.length;
            return Math.hypot(x - d.p[n - 2], y - d.p[n - 1]) < 2 ? d : { ...d, p: [...d.p, x, y] };
        });
    };
    const up = () => {
        if (!draft) return;
        const d = draft;
        setDraft(null);
        // A shape with no size (a click) isn't kept; a pen dot is.
        if (d.t !== 'pen' && Math.hypot(d.p[2] - d.p[0], d.p[3] - d.p[1]) < 4) return;
        save([...shapes, d]);
    };

    // Drag the bottom edge: a taller or shorter drawing.
    const resize = (e: ReactPointerEvent<HTMLDivElement>) => {
        if (!editor.isEditable) return;
        e.preventDefault();
        const startY = e.clientY;
        const startH = height;
        const scale = W / (svg.current?.clientWidth || W);
        let h = startH;
        const onMove = (ev: PointerEvent) => {
            h = Math.round(Math.max(MIN_H, Math.min(MAX_H, startH + (ev.clientY - startY) * scale)));
            setHeight(h);
        };
        const onUp = () => {
            window.removeEventListener('pointermove', onMove);
            window.removeEventListener('pointerup', onUp);
            if (h !== saved) updateAttributes({ height: h });
        };
        window.addEventListener('pointermove', onMove);
        window.addEventListener('pointerup', onUp);
    };

    const btn = (on: boolean, icon: string, label: string, run: () => void, disabled = false) => (
        <button
            type="button"
            className={`ntb-btn${on ? ' is-on' : ''}`}
            aria-pressed={on}
            aria-label={label}
            title={label}
            disabled={disabled}
            onMouseDown={(e) => e.preventDefault()}
            onClick={run}
        >
            <Icon name={icon} />
        </button>
    );

    return (
        <NodeViewWrapper className={`drw${active ? ' is-active' : ''}`} ref={wrap} contentEditable={false} data-drag-handle={active ? undefined : ''}>
            {active && (
                <div className="drw-tools" role="toolbar" aria-label="Drawing">
                    {TOOLS.map(([t, icon, label]) => btn(tool === t, icon, label, () => setTool(t)))}
                    <i className="ntb-gap" />
                    {COLOURS.map(([c, css, label]) => (
                        <button
                            key={c}
                            type="button"
                            className={`drw-swatch${colour === c ? ' is-on' : ''}`}
                            aria-pressed={colour === c}
                            aria-label={label}
                            title={label}
                            style={{ ['--sw' as string]: css }}
                            onMouseDown={(e) => e.preventDefault()}
                            onClick={() => setColour(c)}
                        />
                    ))}
                    <button
                        type="button"
                        className={`drw-thick${thick ? ' is-on' : ''}`}
                        aria-pressed={thick}
                        title={thick ? 'Thick lines' : 'Thin lines'}
                        aria-label="Thick lines"
                        onMouseDown={(e) => e.preventDefault()}
                        onClick={() => setThick((v) => !v)}
                    >
                        <i />
                    </button>
                    <i className="ntb-gap" />
                    {btn(false, 'undo', 'Undo the last one', () => save(shapes.slice(0, -1)), shapes.length === 0)}
                    {btn(false, 'trash', 'Clear the drawing', () => save([]), shapes.length === 0)}
                    <button
                        type="button"
                        className="btn btn-secondary btn-sm drw-done"
                        onMouseDown={(e) => e.preventDefault()}
                        onClick={() => setActive(false)}
                    >
                        Done
                    </button>
                </div>
            )}
            <svg
                ref={svg}
                className={`drw-canvas${active ? ' is-active' : ''}${tool === 'erase' ? ' is-erasing' : ''}`}
                viewBox={`0 0 ${W} ${height}`}
                role="img"
                aria-label={shapes.length ? `Drawing with ${shapes.length} ${shapes.length === 1 ? 'shape' : 'shapes'}` : 'Empty drawing'}
                onPointerDown={down}
                onPointerMove={move}
                onPointerUp={up}
                onPointerCancel={() => setDraft(null)}
            >
                {shapes.map((s, i) => draw(s, i))}
                {draft && draw(draft, 'draft')}
            </svg>
            {!active && shapes.length === 0 && <span className="drw-hint">{editable ? 'Click to draw' : 'Empty drawing'}</span>}
            {editable && <div className="drw-resize" onPointerDown={resize} title="Drag to make it taller or shorter" aria-hidden="true" />}
        </NodeViewWrapper>
    );
}

/** The "drawing" block of the note editor. */
export const Drawing = Node.create({
    name: 'drawing',
    group: 'block',
    atom: true,
    draggable: true,
    selectable: true,

    addAttributes() {
        return {
            strokes: {
                default: [],
                parseHTML: (el) => {
                    try {
                        return shapesOf(JSON.parse(el.getAttribute('data-strokes') ?? '[]'));
                    } catch {
                        return [];
                    }
                },
                renderHTML: (attrs) => ({ 'data-strokes': JSON.stringify(attrs.strokes ?? []) }),
            },
            height: {
                default: 300,
                parseHTML: (el) => Number(el.getAttribute('data-height')) || 300,
                renderHTML: (attrs) => ({ 'data-height': attrs.height }),
            },
        };
    },

    parseHTML() {
        return [{ tag: 'div[data-drawing]' }];
    },

    renderHTML({ HTMLAttributes }) {
        return ['div', mergeAttributes(HTMLAttributes, { 'data-drawing': '' })];
    },

    addNodeView() {
        // Pointer and key events inside an open drawing belong to the drawing, not the text around it.
        return ReactNodeViewRenderer(DrawingView, {
            stopEvent: ({ event }) => !!(event.target as Element | null)?.closest?.('.drw.is-active, .drw-resize'),
        });
    },
});

/** Whether the document holds a drawing (a note with only a drawing isn't empty). */
export const hasDrawing = (doc: { descendants: (f: (n: { type: { name: string } }) => boolean | void) => void }) => {
    let found = false;
    doc.descendants((n) => {
        if (n.type.name === 'drawing') found = true;
        return !found;
    });
    return found;
};
