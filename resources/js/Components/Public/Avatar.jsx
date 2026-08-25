const PALETTE = ['bg-teal-100 text-teal-800', 'bg-amber-100 text-amber-800', 'bg-stone-200 text-stone-800'];

function initials(name) {
    return name
        .replace(/^Dr\.\s*/, '')
        .split(' ')
        .map((part) => part[0])
        .join('')
        .slice(0, 2)
        .toUpperCase();
}

export default function Avatar({ name, size = 'lg' }) {
    const sizes = { md: 'h-12 w-12 text-base', lg: 'h-20 w-20 text-2xl' };
    const colorIndex = name.length % PALETTE.length;

    return (
        <div
            className={`flex shrink-0 items-center justify-center rounded-full font-semibold ${sizes[size]} ${PALETTE[colorIndex]}`}
            role="img"
            aria-label={name}
        >
            {initials(name)}
        </div>
    );
}
