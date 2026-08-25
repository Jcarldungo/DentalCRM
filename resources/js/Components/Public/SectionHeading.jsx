export default function SectionHeading({ eyebrow, title, subtitle, align = 'center', as = 'h2' }) {
    const alignment = align === 'left' ? 'text-left items-start' : 'text-center items-center mx-auto';
    const Heading = as;

    return (
        <div className={`flex max-w-2xl flex-col gap-3 ${alignment}`}>
            {eyebrow && (
                <span className="text-sm font-semibold uppercase tracking-wide text-teal-700">
                    {eyebrow}
                </span>
            )}
            <Heading className="text-3xl font-semibold tracking-tight text-stone-900 sm:text-4xl">
                {title}
            </Heading>
            {subtitle && <p className="text-lg leading-relaxed text-stone-600">{subtitle}</p>}
        </div>
    );
}
