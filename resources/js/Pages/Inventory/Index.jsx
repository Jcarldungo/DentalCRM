import Button from '@/Components/UI/Button';
import Card from '@/Components/UI/Card';
import Field, { SelectField, TextareaField } from '@/Components/UI/Field';
import Modal from '@/Components/UI/Modal';
import { EmptyState, PageContainer, PageHeader } from '@/Components/UI/Page';
import StatusBadge from '@/Components/UI/StatusBadge';
import { stockStatus } from '@/Components/UI/statuses';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { Boxes, Plus, Search } from 'lucide-react';
import { useEffect, useState } from 'react';
import { formatDate } from '@/Pages/Patients/format';
import { CATEGORIES, UNITS, categoryLabel } from './shared';

const FILTERS = [
    { key: 'all', label: 'All' },
    { key: 'low', label: 'Low stock' },
    { key: 'expiring', label: 'Expiring' },
    { key: 'archived', label: 'Archived' },
];

const EMPTY_COPY = {
    low: 'Nothing is at or below its reorder threshold.',
    expiring: 'Nothing is expiring in the next 30 days.',
    archived: 'No archived items. Archiving keeps an item and its ledger but hides it from the default view.',
    all: 'Add an item to start tracking stock. On-hand is derived from its movement ledger, never entered directly.',
};

export default function Index({ items, filters }) {
    const [showCreate, setShowCreate] = useState(false);
    const [search, setSearch] = useState(filters.search ?? '');

    useEffect(() => {
        if (search === (filters.search ?? '')) return undefined;

        const timer = setTimeout(() => {
            router.get(
                route('inventory.index'),
                { filter: filters.filter, search: search || undefined },
                { preserveState: true, preserveScroll: true, replace: true },
            );
        }, 300);

        return () => clearTimeout(timer);
    }, [search, filters.filter, filters.search]);

    const form = useForm({
        name: '',
        category: 'consumable',
        unit: '',
        reorder_threshold: '',
        supplier: '',
        expiry_date: '',
        notes: '',
        opening_quantity: '',
    });

    function submitCreate(event) {
        event.preventDefault();
        form.post(route('inventory.store'), {
            onSuccess: () => {
                form.reset();
                setShowCreate(false);
            },
        });
    }

    return (
        <AuthenticatedLayout
            title="Inventory"
            actions={
                <Button size="sm" icon={Plus} onClick={() => { form.clearErrors(); setShowCreate(true); }}>
                    New item
                </Button>
            }
        >
            <Head title="Inventory" />

            <PageContainer>
                <PageHeader
                    title="Inventory"
                    description={`${items.length} item${items.length === 1 ? '' : 's'} in this view`}
                    actions={
                        <Button
                            icon={Plus}
                            onClick={() => { form.clearErrors(); setShowCreate(true); }}
                            className="hidden lg:inline-flex"
                        >
                            New item
                        </Button>
                    }
                />

                <div className="mb-4 flex flex-wrap items-center gap-3">
                    <div role="group" aria-label="Filter inventory" className="flex flex-wrap gap-1.5">
                        {FILTERS.map((filter) => {
                            const active = filters.filter === filter.key;

                            return (
                                <Link
                                    key={filter.key}
                                    href={route('inventory.index', {
                                        filter: filter.key,
                                        search: search || undefined,
                                    })}
                                    preserveState
                                    preserveScroll
                                    replace
                                    aria-current={active ? 'true' : undefined}
                                    className={`inline-flex h-9 items-center rounded-lg px-3 text-sm font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500 focus-visible:ring-offset-2 ${
                                        active
                                            ? 'bg-brand-600 text-white'
                                            : 'border border-slate-300 bg-white text-slate-700 hover:bg-slate-50'
                                    }`}
                                >
                                    {filter.label}
                                </Link>
                            );
                        })}
                    </div>

                    <div className="relative w-full max-w-xs">
                        <Search
                            className="pointer-events-none absolute start-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400"
                            aria-hidden="true"
                        />
                        <input
                            type="search"
                            aria-label="Search inventory by name"
                            placeholder="Search by name…"
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            className="h-9 w-full rounded-lg border-slate-300 ps-9 text-sm shadow-sm placeholder:text-slate-400 focus:border-brand-500 focus:ring-brand-500"
                        />
                    </div>
                </div>

                <Card className="overflow-hidden">
                    {items.length === 0 ? (
                        <EmptyState
                            icon={Boxes}
                            title="Nothing here"
                            description={EMPTY_COPY[filters.filter] ?? EMPTY_COPY.all}
                        />
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="w-full min-w-[44rem] text-sm">
                                <thead>
                                    <tr className="border-b border-slate-200 bg-slate-50 text-left">
                                        <th scope="col" className="px-4 py-2.5 font-medium text-slate-600 sm:px-5">
                                            Item
                                        </th>
                                        <th scope="col" className="px-4 py-2.5 font-medium text-slate-600">
                                            Category
                                        </th>
                                        <th scope="col" className="px-4 py-2.5 text-right font-medium text-slate-600">
                                            On hand
                                        </th>
                                        <th scope="col" className="px-4 py-2.5 text-right font-medium text-slate-600">
                                            Reorder at
                                        </th>
                                        <th scope="col" className="px-4 py-2.5 font-medium text-slate-600">
                                            Status
                                        </th>
                                        <th scope="col" className="px-4 py-2.5 font-medium text-slate-600">
                                            Expiry
                                        </th>
                                        <th scope="col" className="px-4 py-2.5 font-medium text-slate-600 sm:px-5">
                                            Supplier
                                        </th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-200">
                                    {items.map((item) => (
                                        <tr key={item.id} className="transition-colors hover:bg-slate-50">
                                            <td className="px-4 py-2.5 sm:px-5">
                                                <Link
                                                    href={route('inventory.show', item.id)}
                                                    className="font-medium text-slate-900 hover:text-brand-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500"
                                                >
                                                    {item.name}
                                                </Link>
                                            </td>
                                            <td className="px-4 py-2.5 capitalize text-slate-600">
                                                {categoryLabel(item.category)}
                                            </td>
                                            <td className="tabular px-4 py-2.5 text-right font-medium text-slate-900">
                                                {item.on_hand}{' '}
                                                <span className="font-normal text-slate-500">{item.unit}</span>
                                            </td>
                                            <td className="tabular px-4 py-2.5 text-right text-slate-500">
                                                {item.reorder_threshold}
                                            </td>
                                            <td className="px-4 py-2.5">
                                                <StatusBadge status={stockStatus(item.stock_status)} />
                                            </td>
                                            <td
                                                className={`tabular px-4 py-2.5 ${
                                                    item.is_expiring_soon ? 'font-medium text-amber-700' : 'text-slate-500'
                                                }`}
                                            >
                                                {item.expiry_date ? formatDate(item.expiry_date) : '—'}
                                            </td>
                                            <td className="px-4 py-2.5 text-slate-500 sm:px-5">
                                                {item.supplier ?? '—'}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </Card>
            </PageContainer>

            <Modal
                as="form"
                onSubmit={submitCreate}
                show={showCreate}
                onClose={() => setShowCreate(false)}
                closeable={!form.processing}
                title="New inventory item"
                description="An opening quantity is recorded as a `received` movement, so the ledger stays the only source of on-hand."
                width="2xl"
                footer={
                    <>
                        <Button variant="secondary" onClick={() => setShowCreate(false)} disabled={form.processing}>
                            Cancel
                        </Button>
                        <Button type="submit" disabled={form.processing}>
                            {form.processing ? 'Creating…' : 'Create item'}
                        </Button>
                    </>
                }
            >
                <div className="space-y-4">
                    <Field
                        label="Name"
                        required
                        value={form.data.name}
                        onChange={(e) => form.setData('name', e.target.value)}
                        error={form.errors.name}
                    />

                    <div className="grid gap-4 sm:grid-cols-2">
                        <SelectField
                            label="Category"
                            value={form.data.category}
                            onChange={(e) => form.setData('category', e.target.value)}
                            error={form.errors.category}
                        >
                            {CATEGORIES.map((category) => (
                                <option key={category} value={category}>
                                    {categoryLabel(category)}
                                </option>
                            ))}
                        </SelectField>

                        <div>
                            <Field
                                label="Unit"
                                required
                                list="inventory-units"
                                value={form.data.unit}
                                onChange={(e) => form.setData('unit', e.target.value)}
                                error={form.errors.unit}
                                hint="Whole units only — movements are integers."
                            />
                            <datalist id="inventory-units">
                                {UNITS.map((unit) => (
                                    <option key={unit} value={unit} />
                                ))}
                            </datalist>
                        </div>

                        <Field
                            label="Reorder threshold"
                            required
                            type="number"
                            min="0"
                            inputClassName="tabular"
                            value={form.data.reorder_threshold}
                            onChange={(e) => form.setData('reorder_threshold', e.target.value)}
                            error={form.errors.reorder_threshold}
                            hint="At or below this, the item reads as low stock."
                        />
                        <Field
                            label="Opening quantity"
                            type="number"
                            min="0"
                            inputClassName="tabular"
                            value={form.data.opening_quantity}
                            onChange={(e) => form.setData('opening_quantity', e.target.value)}
                            error={form.errors.opening_quantity}
                        />
                        <Field
                            label="Supplier"
                            value={form.data.supplier}
                            onChange={(e) => form.setData('supplier', e.target.value)}
                            error={form.errors.supplier}
                        />
                        <Field
                            label="Expiry date"
                            type="date"
                            value={form.data.expiry_date}
                            onChange={(e) => form.setData('expiry_date', e.target.value)}
                            error={form.errors.expiry_date}
                            hint="One date per item — batches are not tracked separately."
                        />
                    </div>

                    <TextareaField
                        label="Notes"
                        rows={2}
                        value={form.data.notes}
                        onChange={(e) => form.setData('notes', e.target.value)}
                        error={form.errors.notes}
                    />
                </div>
            </Modal>
        </AuthenticatedLayout>
    );
}
