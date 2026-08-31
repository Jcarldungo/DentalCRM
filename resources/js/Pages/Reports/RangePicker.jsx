import { useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import Button from '@/Components/UI/Button';
import { controlClasses } from '@/Components/UI/Field';

const PRESETS = [
    ['this_month', 'This month'],
    ['last_month', 'Last month'],
    ['this_quarter', 'This quarter'],
    ['ytd', 'Year to date'],
    ['last_12_months', 'Last 12 months'],
    ['custom', 'Custom'],
];

export default function RangePicker({ meta }) {
    const { errors } = usePage().props;
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
        <div className="sticky top-16 z-20 -mx-4 mb-6 border-y border-slate-200 bg-slate-50/95 px-4 py-3 backdrop-blur sm:mx-0 sm:rounded-2xl sm:border">
            <div role="group" aria-label="Report date range" className="flex flex-wrap items-center gap-1.5">
                {PRESETS.map(([value, text]) => (
                    <Button
                        key={value}
                        size="sm"
                        variant={meta.range === value ? 'primary' : 'secondary'}
                        onClick={() => pick(value)}
                        aria-pressed={meta.range === value}
                    >
                        {text}
                    </Button>
                ))}
                <span className="ms-1 text-sm text-slate-500">{meta.label}</span>
            </div>

            {meta.range === 'custom' && (
                <div className="mt-3 flex flex-wrap items-end gap-2">
                    <label className="text-xs font-medium text-slate-600">
                        From
                        <input
                            type="date"
                            value={start}
                            onChange={(e) => setStart(e.target.value)}
                            className={`mt-1 h-9 ${controlClasses}`}
                        />
                    </label>
                    <label className="text-xs font-medium text-slate-600">
                        To
                        <input
                            type="date"
                            value={end}
                            onChange={(e) => setEnd(e.target.value)}
                            className={`mt-1 h-9 ${controlClasses}`}
                        />
                    </label>
                    <Button size="sm" onClick={() => go({ range: 'custom', start, end })}>
                        Apply
                    </Button>
                    {(errors.start || errors.end || errors.range) && (
                        <p role="alert" className="mt-2 w-full text-sm font-medium text-rose-600">
                            {errors.start || errors.end || errors.range}
                        </p>
                    )}
                </div>
            )}
        </div>
    );
}
