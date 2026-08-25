import Avatar from '@/Components/Public/Avatar';

export default function DentistCard({ dentist }) {
    return (
        <div className="flex flex-col items-center gap-4 rounded-lg border border-stone-200 bg-white p-8 text-center">
            <Avatar name={dentist.name} size="lg" />
            <div>
                <h3 className="text-lg font-semibold text-stone-900">{dentist.name}</h3>
                <p className="text-sm font-medium text-teal-700">{dentist.specialty}</p>
            </div>
            <p className="text-sm leading-relaxed text-stone-600">{dentist.bio}</p>
            <ul className="flex flex-wrap justify-center gap-2">
                {dentist.credentials.map((credential) => (
                    <li
                        key={credential}
                        className="rounded-full bg-stone-100 px-3 py-1 text-xs font-medium text-stone-600"
                    >
                        {credential}
                    </li>
                ))}
            </ul>
            <p className="text-xs text-stone-500">{dentist.experience}</p>
        </div>
    );
}
