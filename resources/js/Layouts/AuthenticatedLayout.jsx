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
    ChevronRight,
    History,
    Home,
    PanelLeft,
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
 * The rail is dark. It is the app's only chrome, and against the light
 * content it reads as a frame rather than as a second white column with a
 * hairline down it — which is what had the navigation and the page
 * competing for the same attention. Its colours are the named `sidebar-*`
 * roles in tailwind.config.js, not inline hexes.
 *
 * From `lg` up the rail collapses to a 64px icon strip and the choice
 * persists, because a clinician on a 1024px tablet wants the horizontal
 * space back for a patient record but does not want to re-collapse it on
 * every navigation. Below `lg` the same nav is an off-canvas drawer;
 * there is one markup path for all three states, not three.
 */
const COLLAPSE_KEY = 'staff.sidebar.collapsed';

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

function NavItem({ item, badge, collapsed, onNavigate }) {
    const active = route().current(item.match);
    const Icon = item.icon;

    return (
        <Link
            href={route(item.route)}
            onClick={onNavigate}
            aria-current={active ? 'page' : undefined}
            title={collapsed ? item.name : undefined}
            className={`group relative flex items-center rounded-xl text-sm font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-white/70 ${
                collapsed ? 'justify-center py-2.5' : 'gap-3 px-3 py-2.5'
            } ${
                active
                    ? 'bg-white text-sidebar shadow-sm'
                    : 'text-sidebar-text hover:bg-sidebar-raised hover:text-white'
            }`}
        >
            <Icon
                className={`h-[18px] w-[18px] shrink-0 ${
                    active ? 'text-sidebar' : 'text-sidebar-muted group-hover:text-white'
                }`}
                aria-hidden="true"
            />
            <span className={collapsed ? 'sr-only' : 'flex-1 truncate'}>{item.name}</span>
            {badge > 0 &&
                (collapsed ? (
                    <span
                        className="absolute end-2 top-1.5 h-2 w-2 rounded-full bg-amber-400"
                        aria-hidden="true"
                    />
                ) : (
                    <span className="tabular rounded-full bg-amber-400 px-1.5 py-0.5 text-xs font-semibold text-amber-950">
                        {badge}
                    </span>
                ))}
        </Link>
    );
}

function SidebarNav({ clinicName, badges, collapsed = false, onNavigate }) {
    return (
        <div className="flex h-full flex-col bg-sidebar">
            <div
                className={`flex h-16 shrink-0 items-center border-b border-sidebar-border ${
                    collapsed ? 'justify-center px-2' : 'px-4'
                }`}
            >
                <Link
                    href={route('dashboard')}
                    onClick={onNavigate}
                    className="min-w-0 rounded-xl focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-white/70"
                >
                    <ClinicMark
                        name={clinicName}
                        subtitle="Management system"
                        compact={collapsed}
                        onDark
                    />
                    {collapsed && <span className="sr-only">{clinicName}</span>}
                </Link>
            </div>

            <nav
                aria-label="Main"
                className={`flex-1 space-y-6 overflow-y-auto py-5 ${collapsed ? 'px-2' : 'px-3'}`}
            >
                {NAV_GROUPS.map((group) => (
                    <div key={group.label}>
                        {collapsed ? (
                            <div className="mx-auto mb-2 h-px w-6 bg-sidebar-border" aria-hidden="true" />
                        ) : (
                            <p className="px-3 pb-2 text-[11px] font-semibold uppercase tracking-wider text-sidebar-muted">
                                {group.label}
                            </p>
                        )}
                        <div className="space-y-1">
                            {group.items.map((item) => (
                                <NavItem
                                    key={item.route}
                                    item={item}
                                    badge={badges?.[item.route]}
                                    collapsed={collapsed}
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

/**
 * Where you are, one line above the page title.
 *
 * Most staff destinations are one level deep, so this is usually Home
 * plus the section. It earns its place on the detail pages: a patient
 * record opened from the queue gave no clue which list would take you
 * back, and a page can now pass its own trail to say so.
 */
function Breadcrumbs({ trail }) {
    return (
        <nav aria-label="Breadcrumb" className="min-w-0">
            <ol className="flex items-center gap-1 text-sm">
                <li className="flex items-center">
                    <Link
                        href={route('dashboard')}
                        className="flex items-center gap-1.5 rounded text-slate-500 transition-colors hover:text-slate-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500"
                    >
                        <Home className="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                        <span className="sr-only sm:not-sr-only">Home</span>
                    </Link>
                </li>
                {trail.map((crumb, index) => {
                    const last = index === trail.length - 1;

                    return (
                        <li key={`${crumb.label}-${index}`} className="flex min-w-0 items-center">
                            <ChevronRight
                                className="h-3.5 w-3.5 shrink-0 text-slate-300"
                                aria-hidden="true"
                            />
                            {crumb.href && !last ? (
                                <Link
                                    href={crumb.href}
                                    className="truncate rounded px-1 text-slate-500 transition-colors hover:text-slate-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500"
                                >
                                    {crumb.label}
                                </Link>
                            ) : (
                                <span
                                    aria-current={last ? 'page' : undefined}
                                    className="truncate px-1 font-medium text-slate-700"
                                >
                                    {crumb.label}
                                </span>
                            )}
                        </li>
                    );
                })}
            </ol>
        </nav>
    );
}

function AccountMenu({ user }) {
    return (
        <Menu as="div" className="relative">
            <MenuButton className="flex items-center gap-2.5 rounded-xl px-2 py-1.5 text-sm transition-colors hover:bg-slate-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500">
                <span className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-brand-600 text-xs font-semibold text-white">
                    {initials(user.name)}
                </span>
                <span className="hidden min-w-0 text-start sm:block">
                    <span className="block max-w-[10rem] truncate text-sm font-medium leading-tight text-slate-900">
                        {user.name}
                    </span>
                    <span className="block max-w-[10rem] truncate text-xs leading-tight text-slate-500">
                        {user.email}
                    </span>
                </span>
                <ChevronDown className="h-4 w-4 shrink-0 text-slate-400" aria-hidden="true" />
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
                <MenuItems className="absolute end-0 z-50 mt-1.5 w-60 origin-top-right overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-lg focus:outline-none">
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

/** Read in the initialiser, so a collapsed rail never flashes open first. */
function storedCollapsed() {
    if (typeof window === 'undefined') return false;

    try {
        return window.localStorage.getItem(COLLAPSE_KEY) === '1';
    } catch {
        return false;
    }
}

export default function AuthenticatedLayout({ title, breadcrumbs, actions, navBadges, children }) {
    const { auth, clinic } = usePage().props;
    const [drawerOpen, setDrawerOpen] = useState(false);
    const [collapsed, setCollapsed] = useState(storedCollapsed);

    // A drawer left open across a client-side navigation would cover the
    // page it just navigated to.
    useEffect(() => router.on('navigate', () => setDrawerOpen(false)), []);

    useEffect(() => {
        try {
            window.localStorage.setItem(COLLAPSE_KEY, collapsed ? '1' : '0');
        } catch {
            /* A browser refusing storage still gets a working sidebar. */
        }
    }, [collapsed]);

    const trail = breadcrumbs ?? (title ? [{ label: title }] : []);

    return (
        // White, not `slate-50`. The grey page existed to make white cards
        // float, and floating white cards on grey is the admin-panel look
        // the app is moving away from. With structure carried by rules and
        // space, the content sits directly on the page and the dark rail is
        // the only frame it needs.
        <div className="min-h-screen bg-white">
            <a
                href="#main"
                className="sr-only z-[70] rounded-lg bg-brand-600 px-4 py-2 text-sm font-medium text-white focus:not-sr-only focus:fixed focus:left-4 focus:top-4"
            >
                Skip to content
            </a>

            <Toast />

            {/* Persistent rail from lg up. */}
            <div
                className={`fixed inset-y-0 start-0 z-40 hidden bg-sidebar lg:block ${
                    collapsed ? 'w-16' : 'w-64'
                }`}
            >
                <SidebarNav clinicName={clinic.name} badges={navBadges} collapsed={collapsed} />
            </div>

            {/* The same nav as an off-canvas drawer below lg. */}
            {drawerOpen && (
                <div className="fixed inset-0 z-50 lg:hidden">
                    <div
                        className="fixed inset-0 bg-slate-900/50"
                        onClick={() => setDrawerOpen(false)}
                        aria-hidden="true"
                    />
                    <div className="fixed inset-y-0 start-0 flex w-72 max-w-[85vw] flex-col bg-sidebar shadow-xl">
                        <button
                            type="button"
                            onClick={() => setDrawerOpen(false)}
                            aria-label="Close navigation"
                            className="absolute end-3 top-4 rounded-lg p-1.5 text-sidebar-muted hover:bg-sidebar-raised hover:text-white focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-white/70"
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

            <div className={collapsed ? 'lg:ps-16' : 'lg:ps-64'}>
                <header className="sticky top-0 z-30 border-b border-slate-200 bg-white/90 backdrop-blur">
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

                        <button
                            type="button"
                            onClick={() => setCollapsed((value) => !value)}
                            aria-label={collapsed ? 'Expand navigation' : 'Collapse navigation'}
                            aria-pressed={collapsed}
                            className="-ms-1.5 hidden rounded-lg p-2 text-slate-500 transition-colors hover:bg-slate-100 hover:text-slate-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500 lg:block"
                        >
                            <PanelLeft className="h-5 w-5" aria-hidden="true" />
                        </button>

                        <div className="min-w-0 flex-1">
                            {trail.length > 0 && <Breadcrumbs trail={trail} />}
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
