export default function Badge({ tone = 'muted', children }) {
    const tones = {
        ok: 'bg-green-100 text-green-800',
        muted: 'bg-gray-100 text-gray-600',
        warn: 'bg-amber-100 text-amber-800',
    };

    return (
        <span className={`inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ${tones[tone] ?? tones.muted}`}>
            {children}
        </span>
    );
}
