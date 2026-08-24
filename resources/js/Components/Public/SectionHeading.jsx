export default function SectionHeading({ eyebrow, title, subtitle, align = 'center' }) {
    const alignment = align === 'left' ? 'text-left items-start' : 'text-center items-center mx-auto';

    return (
        <div className={`flex max-w-2xl flex-col gap-3 ${alignment}`}>
            {eyebrow && (
                <span className="text-sm font-semibold uppercase tracking-wide text-teal-700">
                    {eyebrow}
                </span>
            )}
            <h2 className="text-3xl font-semibold tracking-tight text-stone-900 sm:text-4xl">
                {title}
            </h2>
            {subtitle && <p className="text-lg leading-relaxed text-stone-600">{subtitle}</p>}
        </div>
    );
}
