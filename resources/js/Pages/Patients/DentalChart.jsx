import { TOOTH_CONDITIONS, UNCHARTED_TOOTH, toothCondition } from '@/Components/UI/statuses';

/**
 * The odontogram.
 *
 * The previous chart was two flat rows of sixteen 36px squares, which
 * gave a dentist nothing to orient by: no arch, no quadrants, no midline,
 * no left/right, and condition carried by colour alone. It was also 640px
 * wide, so it pushed the patient page into a horizontal scroll on a
 * phone.
 *
 * This lays the teeth out anatomically — upper arch above, lower below,
 * split at the midline, with the quadrants separated and marked. Universal
 * numbering runs 1–16 across the top and 32–17 across the bottom, and both
 * arches are drawn as if facing the patient, so the patient's RIGHT is on
 * the viewer's LEFT. That convention is invisible unless it is labelled,
 * so it is: the arches carry `R` and `L` markers.
 *
 * Colour is never the only channel. Each tooth's accessible name is
 * "Tooth 30, caries, 2 entries", so a screen-reader user gets the chart
 * the same way a sighted one does, and the legend lists only the
 * conditions this patient actually has rather than all nine every time.
 */

/* Universal numbering, patient's upper-right (1) round to upper-left (16). */
const UPPER_RIGHT = [1, 2, 3, 4, 5, 6, 7, 8];
const UPPER_LEFT = [9, 10, 11, 12, 13, 14, 15, 16];
/* Lower arch runs the other way: patient's lower-left (17) round to lower-right (32). */
const LOWER_RIGHT = [32, 31, 30, 29, 28, 27, 26, 25];
const LOWER_LEFT = [24, 23, 22, 21, 20, 19, 18, 17];

export const ALL_TEETH = [...UPPER_RIGHT, ...UPPER_LEFT, ...LOWER_LEFT, ...LOWER_RIGHT].sort(
    (a, b) => a - b,
);

function Tooth({ number, entries, selected, onSelect }) {
    // A tooth's current condition is its newest entry — derived, never
    // stored, because ToothCondition is append-only. `entries` arrives
    // newest-first from Patient::toothConditions().
    const current = entries[0] ?? null;
    const style = current ? toothCondition(current.condition) : UNCHARTED_TOOTH;
    const label = current ? toothCondition(current.condition).label : 'no history';

    return (
        <button
            type="button"
            onClick={() => onSelect(number)}
            aria-pressed={selected}
            aria-label={`Tooth ${number}, ${label}${entries.length ? `, ${entries.length} entr${entries.length === 1 ? 'y' : 'ies'}` : ''}`}
            className={`tabular relative h-10 w-10 shrink-0 rounded-md border text-xs font-semibold transition-[transform,box-shadow] duration-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500 focus-visible:ring-offset-1 ${style.swatch} ${
                selected
                    ? 'ring-2 ring-brand-600 ring-offset-2 z-10'
                    : 'hover:-translate-y-0.5 hover:shadow-sm'
            }`}
        >
            {number}
            {entries.length > 1 && (
                <span
                    className="absolute -end-1 -top-1 flex h-4 min-w-4 items-center justify-center rounded-full bg-slate-700 px-1 text-[10px] font-semibold leading-none text-white"
                    aria-hidden="true"
                >
                    {entries.length}
                </span>
            )}
        </button>
    );
}

function Quadrant({ teeth, byTooth, selected, onSelect }) {
    return (
        <div className="flex gap-1">
            {teeth.map((number) => (
                <Tooth
                    key={number}
                    number={number}
                    entries={byTooth[number] ?? []}
                    selected={selected === number}
                    onSelect={onSelect}
                />
            ))}
        </div>
    );
}

function SideMarker({ children }) {
    return (
        <span
            className="w-4 shrink-0 text-center text-[11px] font-semibold text-slate-400"
            aria-hidden="true"
        >
            {children}
        </span>
    );
}

export default function DentalChart({ toothConditions, selected, onSelect }) {
    const byTooth = toothConditions.reduce((map, entry) => {
        (map[entry.tooth_number] ??= []).push(entry);
        return map;
    }, {});

    // Only the conditions actually present — a nine-item legend on every
    // chart is noise the reader has to filter past on every visit.
    const present = [...new Set(toothConditions.map((entry) => entry.condition))].filter(
        (condition) => condition in TOOTH_CONDITIONS,
    );

    return (
        <div>
            {/* Scrolls inside itself rather than widening the document. */}
            <div className="-mx-4 overflow-x-auto px-4 pb-2 sm:mx-0 sm:px-0">
                <div className="mx-auto w-max">
                    <p className="mb-1.5 text-center text-[11px] font-semibold uppercase tracking-wider text-slate-400">
                        Upper
                    </p>

                    <div className="flex items-center gap-2">
                        <SideMarker>R</SideMarker>
                        <Quadrant teeth={UPPER_RIGHT} byTooth={byTooth} selected={selected} onSelect={onSelect} />
                        <span className="h-10 w-px shrink-0 bg-slate-300" aria-hidden="true" />
                        <Quadrant teeth={UPPER_LEFT} byTooth={byTooth} selected={selected} onSelect={onSelect} />
                        <SideMarker>L</SideMarker>
                    </div>

                    <div className="my-2 flex items-center gap-2" aria-hidden="true">
                        <span className="w-4" />
                        <span className="h-px flex-1 bg-slate-200" />
                        <span className="w-4" />
                    </div>

                    <div className="flex items-center gap-2">
                        <SideMarker>R</SideMarker>
                        <Quadrant teeth={LOWER_RIGHT} byTooth={byTooth} selected={selected} onSelect={onSelect} />
                        <span className="h-10 w-px shrink-0 bg-slate-300" aria-hidden="true" />
                        <Quadrant teeth={LOWER_LEFT} byTooth={byTooth} selected={selected} onSelect={onSelect} />
                        <SideMarker>L</SideMarker>
                    </div>

                    <p className="mt-1.5 text-center text-[11px] font-semibold uppercase tracking-wider text-slate-400">
                        Lower
                    </p>
                </div>
            </div>

            <p className="mt-3 text-xs text-slate-500">
                Universal numbering, shown as if facing the patient — their right is on your left.
                Select a tooth to see its history or add an entry.
            </p>

            {present.length > 0 && (
                <div className="mt-4 border-t border-slate-200 pt-3">
                    <h3 className="text-xs font-medium text-slate-500">Charted on this patient</h3>
                    <ul className="mt-2 flex flex-wrap gap-x-4 gap-y-1.5">
                        {present.map((condition) => (
                            <li key={condition} className="flex items-center gap-1.5 text-xs text-slate-600">
                                <span
                                    className={`inline-block h-3 w-3 shrink-0 rounded border ${TOOTH_CONDITIONS[condition].dot}`}
                                    aria-hidden="true"
                                />
                                {TOOTH_CONDITIONS[condition].label}
                            </li>
                        ))}
                    </ul>
                </div>
            )}
        </div>
    );
}
