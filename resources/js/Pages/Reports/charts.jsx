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

const HUE = '#2563eb';
const GRID = '#e5e7eb';
const AXIS = '#9ca3af';

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
        return <p className="py-8 text-center text-sm text-gray-400">No data for this period.</p>;
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
                    labelFormatter={(k) => fmtBucket(k, bucket)}
                />
                <Area type="monotone" dataKey="value" stroke={HUE} strokeWidth={2} fill={HUE} fillOpacity={0.12} />
            </AreaChart>
        </ResponsiveContainer>
    );
}

export function MiniBars({ rows, valueFormat }) {
    const fmt = valueFormat === 'peso' ? formatPeso : fmtCount;
    if (rows.length === 0 || rows.every((r) => r.value === 0)) {
        return <p className="py-6 text-center text-sm text-gray-400">No data for this period.</p>;
    }

    return (
        <ResponsiveContainer width="100%" height={Math.max(120, rows.length * 40)}>
            <BarChart data={rows} layout="vertical" margin={{ top: 4, right: 48, bottom: 4, left: 8 }}>
                <XAxis type="number" hide />
                <YAxis
                    type="category"
                    dataKey="label"
                    width={130}
                    tick={{ fontSize: 12, fill: '#374151' }}
                    tickLine={false}
                    axisLine={false}
                />
                <Tooltip formatter={(v) => [fmt(v), 'Total']} cursor={{ fill: '#f3f4f6' }} />
                <Bar dataKey="value" fill={HUE} radius={[0, 4, 4, 0]} barSize={18} />
            </BarChart>
        </ResponsiveContainer>
    );
}
