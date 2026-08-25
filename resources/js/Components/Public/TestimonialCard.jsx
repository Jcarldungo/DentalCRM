import { Quote } from 'lucide-react';

export default function TestimonialCard({ testimonial }) {
    return (
        <figure className="flex flex-col gap-4 rounded-lg border border-stone-200 bg-white p-6">
            <Quote className="h-6 w-6 text-teal-700" aria-hidden="true" />
            <blockquote className="text-sm leading-relaxed text-stone-700">
                &ldquo;{testimonial.quote}&rdquo;
            </blockquote>
            <figcaption className="text-sm">
                <span className="font-semibold text-stone-900">{testimonial.name}</span>
                {testimonial.service && <span className="text-stone-500"> — {testimonial.service}</span>}
            </figcaption>
        </figure>
    );
}
