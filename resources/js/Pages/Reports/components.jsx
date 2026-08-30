import { Link } from '@inertiajs/react';

export function Section({ title, children }) {
    return (
        <section className="space-y-4">
            <h2 className="text-base font-semibold text-slate-900">{title}</h2>
            {children}
        </section>
    );
}

export function StatTile({ label, value, sub }) {
    return (
        <div className="rounded-xl border border-slate-200 bg-white p-4">
            <div className="text-xs uppercase tracking-wide text-slate-500">{label}</div>
            <div className="tabular mt-1 text-2xl font-semibold text-slate-900">{value}</div>
            {sub && <div className="mt-0.5 text-xs text-slate-500">{sub}</div>}
        </div>
    );
}

export function Card({ title, note, children }) {
    return (
        <div className="rounded-xl border border-slate-200 bg-white p-4">
            <div className="mb-2 flex items-baseline justify-between">
                <h4 className="text-sm font-medium text-slate-700">{title}</h4>
                {note && <span className="text-xs text-slate-400">{note}</span>}
            </div>
            {children}
        </div>
    );
}

export function RateBar({ label, value }) {
    const pct = Math.round(value * 1000) / 10;
    return (
        <div>
            <div className="flex justify-between text-sm">
                <span className="text-slate-600">{label}</span>
                <span className="font-medium text-slate-900">{pct}%</span>
            </div>
            <div className="mt-1 h-1.5 rounded-full bg-slate-100">
                <div className="h-1.5 rounded-full bg-brand-600" style={{ width: `${Math.min(pct, 100)}%` }} />
            </div>
        </div>
    );
}

export function NoShowList({ list }) {
    if (list.length === 0) {
        return <p className="py-6 text-center text-sm text-slate-400">No no-shows this period.</p>;
    }
    return (
        <table className="w-full text-sm">
            <tbody>
                {list.map((p) => (
                    <tr key={p.id} className="border-b border-slate-100 last:border-0">
                        <td className="py-2">
                            <Link href={route('patients.show', p.id)} className="font-medium text-brand-700 hover:text-brand-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500">
                                {p.name}
                            </Link>
                        </td>
                        <td className="tabular py-2 text-right text-slate-500">{p.no_show_count}×</td>
                    </tr>
                ))}
            </tbody>
        </table>
    );
}

export function ProviderTable({ rows }) {
    if (rows.length === 0) {
        return <p className="py-6 text-center text-sm text-slate-400">No data for this period.</p>;
    }
    return (
        <table className="w-full text-sm">
            <thead className="text-left text-xs uppercase tracking-wide text-slate-500">
                <tr>
                    <th className="py-1">Provider</th>
                    <th className="py-1 text-right">Total</th>
                    <th className="py-1 text-right">Completed</th>
                    <th className="py-1 text-right">No-show</th>
                </tr>
            </thead>
            <tbody>
                {rows.map((r, i) => (
                    <tr key={i} className="border-b border-slate-100 last:border-0">
                        <td className="py-2">{r.label}</td>
                        <td className="tabular py-2 text-right text-slate-700">{r.total}</td>
                        <td className="tabular py-2 text-right text-slate-700">{r.completed}</td>
                        <td className="tabular py-2 text-right text-slate-700">{r.no_show}</td>
                    </tr>
                ))}
            </tbody>
        </table>
    );
}
