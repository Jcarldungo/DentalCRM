import { useState } from 'react';
import { router } from '@inertiajs/react';

const PRESETS = [
    ['this_month', 'This month'],
    ['last_month', 'Last month'],
    ['this_quarter', 'This quarter'],
    ['ytd', 'Year to date'],
    ['last_12_months', 'Last 12 months'],
    ['custom', 'Custom'],
];

export default function RangePicker({ meta }) {
    const [start, setStart] = useState(meta.start);
    const [end, setEnd] = useState(meta.end);

    function go(params) {
        router.get(route('reports.index'), params, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    }

    function pick(range) {
        if (range === 'custom') {
            go({ range: 'custom', start, end });
        } else {
            go({ range });
        }
    }

    return (
        <div className="sticky top-0 z-10 -mx-4 mb-6 border-b bg-gray-50/95 px-4 py-3 backdrop-blur sm:mx-0 sm:rounded sm:border">
            <div className="flex flex-wrap items-center gap-2">
                {PRESETS.map(([value, text]) => (
                    <button
                        key={value}
                        type="button"
                        onClick={() => pick(value)}
                        className={`rounded border px-3 py-1 text-sm ${
                            meta.range === value
                                ? 'border-gray-900 bg-gray-900 text-white'
                                : 'border-gray-300 text-gray-600'
                        }`}
                    >
                        {text}
                    </button>
                ))}
                <span className="ml-1 text-sm text-gray-500">{meta.label}</span>
            </div>

            {meta.range === 'custom' && (
                <div className="mt-3 flex flex-wrap items-end gap-2">
                    <label className="text-xs text-gray-500">
                        From
                        <input
                            type="date"
                            value={start}
                            onChange={(e) => setStart(e.target.value)}
                            className="mt-1 block rounded border px-2 py-1 text-sm"
                        />
                    </label>
                    <label className="text-xs text-gray-500">
                        To
                        <input
                            type="date"
                            value={end}
                            onChange={(e) => setEnd(e.target.value)}
                            className="mt-1 block rounded border px-2 py-1 text-sm"
                        />
                    </label>
                    <button
                        type="button"
                        onClick={() => go({ range: 'custom', start, end })}
                        className="rounded bg-gray-900 px-3 py-1.5 text-sm text-white"
                    >
                        Apply
                    </button>
                </div>
            )}
        </div>
    );
}
