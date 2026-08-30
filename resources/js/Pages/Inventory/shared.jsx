export const CATEGORIES = ['consumable', 'instrument', 'ppe', 'medication', 'lab_material', 'office'];
export const UNITS = ['box', 'piece', 'pair', 'pack', 'cartridge', 'bottle', 'tube', 'roll', 'ml'];

export const STATUS_BADGE = {
    ok: 'bg-green-100 text-green-800 border-green-300',
    low: 'bg-amber-100 text-amber-800 border-amber-300',
    out: 'bg-red-100 text-red-800 border-red-300',
};

export function categoryLabel(category) {
    return category.replace('_', ' ');
}

export function Field({ label, error, children }) {
    return (
        <div>
            <label className="mb-1 block text-sm">{label}</label>
            {children}
            {error && <p className="text-sm text-red-600">{error}</p>}
        </div>
    );
}

export function Dialog({ children, onClose }) {
    return (
        <div
            className="fixed inset-0 flex items-center justify-center overflow-y-auto bg-black/40 p-4"
            onClick={onClose}
        >
            <div className="my-8 w-full max-w-lg rounded bg-white p-6" onClick={(e) => e.stopPropagation()}>
                {children}
            </div>
        </div>
    );
}
