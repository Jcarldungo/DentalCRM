import { Link } from '@inertiajs/react';

export function Section({ title, children }) {
    return (
        <section className="space-y-4">
            <h3 className="text-lg font-semibold text-gray-900">{title}</h3>
            {children}
        </section>
    );
}

export function StatTile({ label, value, sub }) {
    return (
        <div className="rounded border bg-white p-4 shadow-sm">
            <div className="text-xs uppercase tracking-wide text-gray-500">{label}</div>
            <div className="mt-1 text-2xl font-semibold text-gray-900">{value}</div>
            {sub && <div className="mt-0.5 text-xs text-gray-500">{sub}</div>}
        </div>
    );
}

export function Card({ title, note, children }) {
    return (
        <div className="rounded border bg-white p-4 shadow-sm">
            <div className="mb-2 flex items-baseline justify-between">
                <h4 className="text-sm font-medium text-gray-700">{title}</h4>
                {note && <span className="text-xs text-gray-400">{note}</span>}
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
                <span className="text-gray-600">{label}</span>
                <span className="font-medium text-gray-900">{pct}%</span>
            </div>
            <div className="mt-1 h-1.5 rounded bg-gray-100">
                <div className="h-1.5 rounded bg-blue-600" style={{ width: `${Math.min(pct, 100)}%` }} />
            </div>
        </div>
    );
}

export function NoShowList({ list }) {
    if (list.length === 0) {
        return <p className="py-6 text-center text-sm text-gray-400">No no-shows this period.</p>;
    }
    return (
        <table className="w-full text-sm">
            <tbody>
                {list.map((p) => (
                    <tr key={p.id} className="border-b last:border-0">
                        <td className="py-2">
                            <Link href={route('patients.show', p.id)} className="text-blue-600">
                                {p.name}
                            </Link>
                        </td>
                        <td className="py-2 text-right text-gray-500">{p.no_show_count}×</td>
                    </tr>
                ))}
            </tbody>
        </table>
    );
}

export function ProviderTable({ rows }) {
    if (rows.length === 0) {
        return <p className="py-6 text-center text-sm text-gray-400">No data for this period.</p>;
    }
    return (
        <table className="w-full text-sm">
            <thead className="text-left text-xs uppercase tracking-wide text-gray-500">
                <tr>
                    <th className="py-1">Provider</th>
                    <th className="py-1 text-right">Total</th>
                    <th className="py-1 text-right">Completed</th>
                    <th className="py-1 text-right">No-show</th>
                </tr>
            </thead>
            <tbody>
                {rows.map((r) => (
                    <tr key={r.label} className="border-b last:border-0">
                        <td className="py-2">{r.label}</td>
                        <td className="py-2 text-right">{r.total}</td>
                        <td className="py-2 text-right">{r.completed}</td>
                        <td className="py-2 text-right">{r.no_show}</td>
                    </tr>
                ))}
            </tbody>
        </table>
    );
}
