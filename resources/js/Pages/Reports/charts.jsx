import {
    Area,
    AreaChart,
    Bar,
    BarChart,
    CartesianGrid,
    ResponsiveContainer,
    Tooltip,
    XAxis,
    YAxis,
} from 'recharts';
import { formatPeso } from '@/Pages/Patients/format';

/* Matches brand-600 / slate-200 / slate-400 in tailwind.config.js. */
const HUE = '#2a54a0';
const GRID = '#e2e8f0';
const AXIS = '#94a3b8';

function fmtCount(n) {
    return Number(n).toLocaleString();
}

function fmtBucket(key, bucket) {
    const d = new Date(key + 'T00:00:00');
    if (bucket === 'month') return d.toLocaleDateString(undefined, { month: 'short', year: '2-digit' });
    return d.toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
}

export function TrendChart({ series, bucket, valueFormat }) {
    const fmt = valueFormat === 'peso' ? formatPeso : fmtCount;
    const hasData = series.some((p) => p.value > 0);

    if (!hasData) {
        return <p className="py-8 text-center text-sm text-slate-400">No data for this period.</p>;
    }

    return (
        <ResponsiveContainer width="100%" height={220}>
            <AreaChart data={series} margin={{ top: 8, right: 8, bottom: 0, left: 8 }}>
                <CartesianGrid stroke={GRID} vertical={false} />
                <XAxis
                    dataKey="bucket"
                    tickFormatter={(k) => fmtBucket(k, bucket)}
                    tick={{ fontSize: 11, fill: AXIS }}
                    tickLine={false}
                    axisLine={{ stroke: GRID }}
                    minTickGap={24}
                />
                <YAxis
                    tickFormatter={(v) => (valueFormat === 'peso' ? `₱${Number(v).toLocaleString()}` : fmtCount(v))}
                    tick={{ fontSize: 11, fill: AXIS }}
                    tickLine={false}
                    axisLine={false}
                    width={64}
                />
                <Tooltip
                    formatter={(v) => [fmt(v), valueFormat === 'peso' ? 'Collected' : 'Count']}
                    labelFormatter={(k) =>
                        bucket === 'week' ? `Week of ${fmtBucket(k, 'day')}` : fmtBucket(k, bucket)
                    }
                />
                <Area type="monotone" dataKey="value" stroke={HUE} strokeWidth={2} fill={HUE} fillOpacity={0.12} />
            </AreaChart>
        </ResponsiveContainer>
    );
}

export function MiniBars({ rows, valueFormat }) {
    const fmt = valueFormat === 'peso' ? formatPeso : fmtCount;
    if (rows.length === 0 || rows.every((r) => r.value === 0)) {
        return <p className="py-6 text-center text-sm text-slate-400">No data for this period.</p>;
    }

    if (rows.some((r) => r.sub)) {
        const max = Math.max(...rows.map((r) => r.value), 1);
        return (
            <ul className="space-y-2 py-1">
                {rows.map((r, i) => (
                    <li key={i} className="text-sm">
                        <div className="flex items-baseline justify-between">
                            <span className="text-slate-700">{r.label}</span>
                            <span className="font-medium text-slate-900">{fmt(r.value)}</span>
                        </div>
                        <div className="mt-1 h-1.5 rounded bg-slate-100">
                            <div
                                className="h-1.5 rounded-full bg-brand-600"
                                style={{ width: `${(r.value / max) * 100}%` }}
                            />
                        </div>
                        {r.sub && <div className="mt-0.5 text-xs text-slate-400">{r.sub}</div>}
                    </li>
                ))}
            </ul>
        );
    }

    return (
        <ResponsiveContainer width="100%" height={Math.max(120, rows.length * 40)}>
            <BarChart data={rows} layout="vertical" margin={{ top: 4, right: 48, bottom: 4, left: 8 }}>
                <XAxis type="number" hide />
                <YAxis
                    type="category"
                    dataKey="label"
                    width={130}
                    tick={{ fontSize: 12, fill: '#334155' }}
                    tickLine={false}
                    axisLine={false}
                />
                <Tooltip formatter={(v) => [fmt(v), 'Total']} cursor={{ fill: '#f1f5f9' }} />
                <Bar dataKey="value" fill={HUE} radius={[0, 4, 4, 0]} barSize={18} />
            </BarChart>
        </ResponsiveContainer>
    );
}
