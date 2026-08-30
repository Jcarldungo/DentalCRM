import { useRef } from 'react';

/**
 * A real tab list.
 *
 * The patient page's six tabs were plain buttons in a `flex gap-6` — no
 * `role`, no `aria-selected`, no arrow-key navigation, and at 390px the
 * row was 459px wide, which is what pushed the whole document into a
 * horizontal scroll. This scrolls inside itself instead, and implements
 * the roving-tabindex pattern so a keyboard user tabs into the group once
 * and then arrows between tabs.
 */
export default function Tabs({ tabs, active, onChange, className = '' }) {
    const refs = useRef([]);

    function onKeyDown(event) {
        const index = tabs.findIndex((tab) => tab.id === active);
        let next = null;

        if (event.key === 'ArrowRight') next = (index + 1) % tabs.length;
        if (event.key === 'ArrowLeft') next = (index - 1 + tabs.length) % tabs.length;
        if (event.key === 'Home') next = 0;
        if (event.key === 'End') next = tabs.length - 1;

        if (next === null) return;

        event.preventDefault();
        onChange(tabs[next].id);
        refs.current[next]?.focus();
    }

    return (
        <div className={`-mx-4 overflow-x-auto px-4 sm:mx-0 sm:px-0 ${className}`}>
            <div
                role="tablist"
                aria-label="Patient sections"
                onKeyDown={onKeyDown}
                className="flex w-max min-w-full gap-1 border-b border-slate-200"
            >
                {tabs.map((tab, index) => {
                    const selected = tab.id === active;

                    return (
                        <button
                            key={tab.id}
                            ref={(node) => (refs.current[index] = node)}
                            type="button"
                            role="tab"
                            id={`tab-${tab.id}`}
                            aria-selected={selected}
                            aria-controls={`panel-${tab.id}`}
                            tabIndex={selected ? 0 : -1}
                            onClick={() => onChange(tab.id)}
                            className={`-mb-px flex items-center gap-2 whitespace-nowrap border-b-2 px-3 py-2.5 text-sm font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500 focus-visible:ring-offset-1 ${
                                selected
                                    ? 'border-brand-600 text-brand-700'
                                    : 'border-transparent text-slate-500 hover:border-slate-300 hover:text-slate-800'
                            }`}
                        >
                            {tab.label}
                            {tab.count > 0 && (
                                <span
                                    className={`tabular rounded-full px-1.5 py-0.5 text-xs font-semibold ${
                                        selected ? 'bg-brand-50 text-brand-700' : 'bg-slate-100 text-slate-600'
                                    }`}
                                >
                                    {tab.count}
                                </span>
                            )}
                        </button>
                    );
                })}
            </div>
        </div>
    );
}

export function TabPanel({ id, active, children }) {
    if (id !== active) return null;

    return (
        <div role="tabpanel" id={`panel-${id}`} aria-labelledby={`tab-${id}`} tabIndex={0} className="focus:outline-none">
            {children}
        </div>
    );
}
