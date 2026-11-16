import { Link, router, usePage } from '@inertiajs/react';
import {
    Activity,
    ArrowRight,
    ChartNoAxesCombined,
    ChevronDown,
    FileText,
    FlaskConical,
    LayoutDashboard,
    Menu,
    LogOut,
    Settings,
    ShieldCheck,
    Users,
} from 'lucide-react';
import { useEffect, useState } from 'react';
import { useIsMobile } from '@/hooks/use-mobile';
import {
    Sheet,
    SheetContent,
    SheetTitle,
    SheetTrigger,
} from '@/components/ui/sheet';
import { Errors } from '@/components/hub/shared';
import type { BreadcrumbItem } from '@/types';
const links = [
    { title: 'Overview', href: '/dashboard', icon: LayoutDashboard },
    { title: 'R1 Research', href: '/research', icon: FlaskConical },
    { title: 'Agent outputs', href: '/outputs', icon: FileText },
    { title: 'Portfolio', href: '/portfolio', icon: ChartNoAxesCombined },
    { title: 'Community', href: '/community', icon: Users },
];
export default function AppLayout({
    children,
}: {
    breadcrumbs?: BreadcrumbItem[];
    children: React.ReactNode;
}) {
    // The selected workspace baseline includes portaled account and security dialogs.
    useEffect(() => {
        document.documentElement.classList.add('dark');
    }, []);
    const page = usePage();
    const [open, setOpen] = useState(false);
    const isMobile = useIsMobile();
    const auth = page.props.auth as {
        user?: {
            name?: string;
            email?: string;
            roles?: (string | { name: string })[];
        };
        roles?: string[];
    };
    const roles = [
        ...(auth?.roles ?? []),
        ...(auth?.user?.roles ?? []).map((r) =>
            typeof r === 'string' ? r : r.name,
        ),
    ];
    const canResearch = roles.some((role) =>
        ['researcher', 'owner'].includes(role),
    );
    const owner = [
        ...(auth?.roles ?? []),
        ...(auth?.user?.roles ?? []).map((r) =>
            typeof r === 'string' ? r : r.name,
        ),
    ].includes('owner');
    const navigation = (
        <aside
            className="hub-sidebar"
            onClick={(event) => {
                if (
                    event.target instanceof Element &&
                    event.target.closest('a')
                )
                    setOpen(false);
            }}
        >
            <Link href="/dashboard" className="hub-brand">
                <span className="hub-brand-mark">
                    <Activity className="size-5" />
                </span>
                <span>
                    Kernscale
                    <span className="block text-[10px] font-normal tracking-[.18em] text-muted-foreground uppercase">
                        Trading hub
                    </span>
                </span>
            </Link>
            <div className="mx-4 mb-7 rounded-lg border border-white/10 p-3">
                <div className="flex items-center justify-between text-xs">
                    <span className="flex items-center gap-2">
                        <span className="size-1.5 rounded-full bg-emerald-400" />
                        Member workspace
                    </span>
                    <ChevronDown className="size-3 text-muted-foreground" />
                </div>
                <p className="mt-2 text-[11px] text-muted-foreground">
                    Research. Review. Understand.
                </p>
            </div>
            <p className="hub-nav-label">Workspace</p>
            <nav aria-label="Main navigation">
                {links
                    .filter((link) => link.href !== '/research' || canResearch)
                    .map(({ title, href, icon: Icon }) => (
                        <Link
                            key={href}
                            href={href}
                            onClick={() => setOpen(false)}
                            className={`hub-nav-link ${page.url.split('?')[0] === href ? 'active' : ''}`}
                        >
                            <Icon className="size-[18px]" />
                            {title}
                            {page.url.split('?')[0] === href && (
                                <span className="ml-auto size-1 rounded-full bg-emerald-300" />
                            )}
                        </Link>
                    ))}
                {owner && (
                    <Link
                        href="/admin"
                        className={`hub-nav-link ${page.url === '/admin' ? 'active' : ''}`}
                    >
                        <ShieldCheck className="size-[18px]" />
                        Administration
                    </Link>
                )}
            </nav>
            <div className="mt-auto px-4 pt-10 pb-5">
                <div className="mb-5 rounded-lg bg-white/[.025] p-4">
                    <p className="text-xs font-medium">
                        Clarity before conviction
                    </p>
                    <p className="mt-2 text-xs leading-5 text-muted-foreground">
                        Evidence-led research. One authoritative paper
                        portfolio.
                    </p>
                    <Link
                        href="/research"
                        className="mt-3 inline-flex items-center gap-2 text-xs text-emerald-300"
                    >
                        Open research <ArrowRight className="size-3" />
                    </Link>
                </div>
                <Link
                    href="/settings/profile"
                    className="flex items-center gap-3 rounded-lg p-2 hover:bg-white/5"
                >
                    <span className="flex size-9 items-center justify-center rounded-full bg-[#303035] text-sm">
                        {auth?.user?.name?.slice(0, 1) ?? 'M'}
                    </span>
                    <span className="min-w-0 flex-1">
                        <span className="block truncate text-xs font-medium">
                            {auth?.user?.name ?? 'Member'}
                        </span>
                        <span className="block text-[11px] text-muted-foreground">
                            Account & security
                        </span>
                    </span>
                    <Settings className="size-4 text-muted-foreground" />
                </Link>
                <button
                    type="button"
                    onClick={() => router.post('/logout')}
                    className="mt-3 flex w-full items-center gap-3 rounded-lg px-2 py-3 text-xs text-muted-foreground hover:bg-white/5 hover:text-foreground"
                >
                    <LogOut className="size-4" />
                    Sign out
                </button>
            </div>
        </aside>
    );
    return (
        <Sheet open={isMobile && open} onOpenChange={setOpen}>
            <div className="hub-shell dark">
                <a
                    href="#main-content"
                    className="sr-only focus:not-sr-only focus:fixed focus:z-50 focus:bg-card focus:p-4"
                >
                    Skip to content
                </a>
                {isMobile ? (
                    <SheetContent
                        side="left"
                        className="hub-shell dark w-56 gap-0 p-0"
                        aria-describedby={undefined}
                    >
                        <SheetTitle className="sr-only">
                            Workspace navigation
                        </SheetTitle>
                        {navigation}
                    </SheetContent>
                ) : (
                    navigation
                )}
                <div className="hub-body">
                    <header className="hub-topbar">
                        {isMobile && (
                            <SheetTrigger asChild>
                                <button
                                    type="button"
                                    aria-label="Open navigation"
                                >
                                    <Menu className="size-5" />
                                </button>
                            </SheetTrigger>
                        )}
                        <span className="text-xs text-muted-foreground">
                            Workspace{' '}
                            <span className="mx-3 text-white/20">/</span>
                            <span className="text-foreground">
                                {links.find((l) => page.url.startsWith(l.href))
                                    ?.title ?? 'Account'}
                            </span>
                        </span>
                        <span className="ml-auto flex items-center gap-2 text-[11px] text-muted-foreground">
                            <ShieldCheck className="size-3.5" />
                            Private member access
                        </span>
                    </header>
                    <main id="main-content" className="hub-main">
                        <Errors
                            errors={
                                (page.props.errors ?? {}) as Record<
                                    string,
                                    string
                                >
                            }
                        />
                        {children}
                    </main>
                </div>
            </div>
        </Sheet>
    );
}
