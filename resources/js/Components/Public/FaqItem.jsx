import { ChevronDown } from 'lucide-react';

export default function FaqItem({ question, answer }) {
    return (
        <details className="group border-b border-stone-200 py-4">
            <summary className="flex cursor-pointer list-none items-center justify-between gap-4 text-left text-base font-medium text-stone-900 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-teal-700">
                {question}
                <ChevronDown className="h-5 w-5 shrink-0 text-stone-500 transition-transform group-open:rotate-180" aria-hidden="true" />
            </summary>
            <p className="mt-3 text-sm leading-relaxed text-stone-600">{answer}</p>
        </details>
    );
}
