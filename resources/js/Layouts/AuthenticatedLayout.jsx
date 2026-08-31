import ClinicMark from '@/Components/UI/ClinicMark';
import Toast from '@/Components/UI/Toast';
import { Link, router, usePage } from '@inertiajs/react';
import {
    Menu as MenuIcon,
    X,
    LayoutDashboard,
    ListChecks,
    Stethoscope,
    Users,
    CalendarDays,
    Inbox,
    Receipt,
    BarChart3,
    Boxes,
    UserCog,
    LogOut,
    UserRound,
    ChevronDown,
    History,
} from 'lucide-react';
import { Fragment, useEffect, useState } from 'react';
import { Menu, MenuButton, MenuItem, MenuItems, Transition } from '@headlessui/react';

/**
 * The staff application shell.
 *
 * A sidebar rather than a top bar: ten destinations in a horizontal
 * `space-x-8` row forced the document to 1207px wide, so every staff page
 * overflowed horizontally at 768px — a chairside tablet had to be panned
 * sideways to read a patient record. Grouping them vertically removes
 * that at the root instead of page by page, and leaves room for icons and
 * for the section labels that make ten destinations scannable.
 *
 * Below `lg` the same nav becomes an off-canvas drawer; there is one
 * markup path, not two.
 */
const NAV_GROUPS = [
    {
        label: 'Today',
        items: [
            { name: 'Dashboard', route: 'dashboard', match: 'dashboard', icon: LayoutDashboard },
            { name: 'Queue', route: 'queue.index', match: 'queue.*', icon: ListChecks },
            { name: 'Workspace', route: 'workspace.index', match: 'workspace.*', icon: Stethoscope },
        ],
    },
    {
        label: 'Records',
        items: [
            { name: 'Patients', route: 'patients.index', match: 'patients.*', icon: Users },
            { name: 'Appointments', route: 'appointments.index', match: 'appointments.index', icon: CalendarDays },
            { name: 'Inquiries', route: 'inquiries.index', match: 'inquiries.index', icon: Inbox },
        ],
    },
    {
        label: 'Practice',
        items: [
            { name: 'Billing', route: 'invoices.index', match: 'invoices.*', icon: Receipt },
            { name: 'Reports', route: 'reports.index', match: 'reports.*', icon: BarChart3 },
            { name: 'Inventory', route: 'inventory.index', match: 'inventory.*', icon: Boxes },
            { name: 'Providers', route: 'providers.index', match: 'providers.*', icon: UserCog },
            { name: 'Activity', route: 'activity.index', match: 'activity.*', icon: History },
        ],
    },
];

function NavItem({ item, badge, onNavigate }) {
    const active = route().current(item.match);
    const Icon = item.icon;

    return (
        <Link
            href={route(item.route)}
            onClick={onNavigate}
            aria-current={active ? 'page' : undefined}
            className={`group flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500 ${
                active
                    ? 'bg-brand-50 text-brand-700'
                    : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900'
            }`}
        >
            <Icon
                className={`h-[18px] w-[18px] shrink-0 ${active ? 'text-brand-600' : 'text-slate-400 group-hover:text-slate-600'}`}
                aria-hidden="true"
            />
            <span className="flex-1 truncate">{item.name}</span>
            {badge > 0 && (
                <span className="tabular rounded-full bg-amber-100 px-1.5 py-0.5 text-xs font-semibold text-amber-800">
                    {badge}
                </span>
            )}
        </Link>
    );
}

function SidebarNav({ clinicName, badges, onNavigate }) {
    return (
        <div className="flex h-full flex-col">
            <div className="flex h-16 shrink-0 items-center border-b border-slate-200 px-4">
                <Link
                    href={route('dashboard')}
                    onClick={onNavigate}
                    className="min-w-0 rounded-lg focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500"
                >
                    <ClinicMark name={clinicName} />
                </Link>
            </div>

            <nav aria-label="Main" className="flex-1 space-y-5 overflow-y-auto px-3 py-4">
                {NAV_GROUPS.map((group) => (
                    <div key={group.label}>
                        <p className="px-3 pb-1.5 text-[11px] font-semibold uppercase tracking-wider text-slate-400">
                            {group.label}
                        </p>
                        <div className="space-y-0.5">
                            {group.items.map((item) => (
                                <NavItem
                                    key={item.route}
                                    item={item}
                                    badge={badges?.[item.route]}
                                    onNavigate={onNavigate}
                                />
                            ))}
                        </div>
                    </div>
                ))}
            </nav>
        </div>
    );
}

function AccountMenu({ user }) {
    return (
        <Menu as="div" className="relative">
            <MenuButton className="flex items-center gap-2 rounded-lg px-2 py-1.5 text-sm text-slate-600 transition-colors hover:bg-slate-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500">
                <span className="flex h-7 w-7 items-center justify-center rounded-full bg-slate-200 text-xs font-semibold text-slate-600">
                    {initials(user.name)}
                </span>
                <span className="hidden max-w-[10rem] truncate font-medium text-slate-700 sm:block">
                    {user.name}
                </span>
                <ChevronDown className="h-4 w-4 text-slate-400" aria-hidden="true" />
            </MenuButton>

            <Transition
                as={Fragment}
                enter="transition ease-out duration-100"
                enterFrom="opacity-0 scale-95"
                enterTo="opacity-100 scale-100"
                leave="transition ease-in duration-75"
                leaveFrom="opacity-100 scale-100"
                leaveTo="opacity-0 scale-95"
            >
                <MenuItems className="absolute end-0 z-50 mt-1.5 w-60 origin-top-right overflow-hidden rounded-xl border border-slate-200 bg-white shadow-lg focus:outline-none">
                    <div className="border-b border-slate-100 px-4 py-3">
                        <p className="truncate text-sm font-medium text-slate-900">{user.name}</p>
                        <p className="truncate text-xs text-slate-500">{user.email}</p>
                    </div>
                    <div className="p-1">
                        <MenuItem>
                            {({ focus }) => (
                                <Link
                                    href={route('profile.edit')}
                                    className={`flex items-center gap-2.5 rounded-lg px-3 py-2 text-sm ${focus ? 'bg-slate-100 text-slate-900' : 'text-slate-700'}`}
                                >
                                    <UserRound className="h-4 w-4 text-slate-400" aria-hidden="true" />
                                    Profile & password
                                </Link>
                            )}
                        </MenuItem>
                        <MenuItem>
                            {({ focus }) => (
                                <button
                                    type="button"
                                    onClick={() => router.post(route('logout'))}
                                    className={`flex w-full items-center gap-2.5 rounded-lg px-3 py-2 text-start text-sm ${focus ? 'bg-slate-100 text-slate-900' : 'text-slate-700'}`}
                                >
                                    <LogOut className="h-4 w-4 text-slate-400" aria-hidden="true" />
                                    Log out
                                </button>
                            )}
                        </MenuItem>
                    </div>
                </MenuItems>
            </Transition>
        </Menu>
    );
}

function initials(name) {
    return String(name ?? '')
        .split(' ')
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part[0]?.toUpperCase())
        .join('');
}

export default function AuthenticatedLayout({ title, actions, navBadges, children }) {
    const { auth, clinic } = usePage().props;
    const [drawerOpen, setDrawerOpen] = useState(false);

    // A drawer left open across a client-side navigation would cover the
    // page it just navigated to.
    useEffect(() => router.on('navigate', () => setDrawerOpen(false)), []);

    return (
        <div className="min-h-screen bg-slate-50">
            <a
                href="#main"
                className="sr-only z-[70] rounded-lg bg-brand-600 px-4 py-2 text-sm font-medium text-white focus:not-sr-only focus:fixed focus:left-4 focus:top-4"
            >
                Skip to content
            </a>

            <Toast />

            {/* Persistent sidebar from lg up. */}
            <div className="fixed inset-y-0 start-0 z-40 hidden w-64 border-e border-slate-200 bg-white lg:block">
                <SidebarNav clinicName={clinic.name} badges={navBadges} />
            </div>

            {/* The same nav as an off-canvas drawer below lg. */}
            {drawerOpen && (
                <div className="fixed inset-0 z-50 lg:hidden">
                    <div
                        className="fixed inset-0 bg-slate-900/40"
                        onClick={() => setDrawerOpen(false)}
                        aria-hidden="true"
                    />
                    <div className="fixed inset-y-0 start-0 flex w-72 max-w-[85vw] flex-col bg-white shadow-xl">
                        <button
                            type="button"
                            onClick={() => setDrawerOpen(false)}
                            aria-label="Close navigation"
                            className="absolute end-3 top-4 rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-600 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500"
                        >
                            <X className="h-5 w-5" aria-hidden="true" />
                        </button>
                        <SidebarNav
                            clinicName={clinic.name}
                            badges={navBadges}
                            onNavigate={() => setDrawerOpen(false)}
                        />
                    </div>
                </div>
            )}

            <div className="lg:ps-64">
                <header className="sticky top-0 z-30 border-b border-slate-200 bg-white/95 backdrop-blur">
                    <div className="flex h-16 items-center gap-3 px-4 sm:px-6 lg:px-8">
                        <button
                            type="button"
                            onClick={() => setDrawerOpen(true)}
                            aria-label="Open navigation"
                            aria-expanded={drawerOpen}
                            className="-ms-1.5 rounded-lg p-2 text-slate-500 transition-colors hover:bg-slate-100 hover:text-slate-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500 lg:hidden"
                        >
                            <MenuIcon className="h-5 w-5" aria-hidden="true" />
                        </button>

                        <div className="min-w-0 flex-1">
                            {title && (
                                <p className="truncate text-sm font-semibold text-slate-900 lg:hidden">
                                    {title}
                                </p>
                            )}
                        </div>

                        {actions && <div className="flex items-center gap-2 lg:hidden">{actions}</div>}

                        <AccountMenu user={auth.user} />
                    </div>
                </header>

                <main id="main">{children}</main>
            </div>
        </div>
    );
}
