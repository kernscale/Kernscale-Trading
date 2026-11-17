// Capitalio registry Sidebar/Brand/NavLink structure; only identity, permissions and real destinations differ.
import { Link, router, usePage } from '@inertiajs/react';
import { Collapsible } from '@base-ui/react/collapsible';
import {
    SidebarAnalyticsIcon,
    SidebarChevronIcon,
    SidebarFolderIcon,
    SidebarHomeMutedIcon,
    SidebarLogoutIcon,
    SidebarNetWorthIcon,
    SidebarSettingsIcon,
    SidebarAiIcon,
    UsersIcon,
    ShieldCheckIcon,
} from '@/components/watermelon/capitalio-dashboard/components/capitalio/icons';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarGroup,
    SidebarGroupContent,
    SidebarGroupLabel,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
    useSidebar,
} from '@/components/ui/sidebar';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import type { ComponentType, SVGProps } from 'react';
type Item = {
    name: string;
    href: string;
    icon: ComponentType<SVGProps<SVGSVGElement>>;
};
function NavLink({ item }: { item: Item }) {
    const { url } = usePage();
    const { isMobile, setOpenMobile } = useSidebar();
    const current = item.href.includes('?')
        ? url.startsWith(item.href)
        : url.split('?')[0] === item.href &&
          !(item.href === '/portfolio' && url.includes('view=analytics'));
    const Icon = item.icon;
    return (
        <SidebarMenuItem>
            <SidebarMenuButton
                asChild
                tooltip={item.name}
                isActive={current}
                className={cn(
                    'h-auto gap-2 rounded-lg px-3 py-3 text-base leading-5 text-muted-foreground transition-colors',
                    'bg-transparent hover:bg-transparent active:bg-transparent data-active:bg-transparent!',
                    'data-active:text-sidebar-accent-foreground',
                    '[&_img]:size-5!',
                    current && 'active-sidebar-item',
                )}
            >
                <Link
                    href={item.href}
                    onClick={() => {
                        if (isMobile) setOpenMobile(false);
                    }}
                    aria-current={current ? 'page' : undefined}
                >
                    <Icon />
                    <span className="truncate">{item.name}</span>
                </Link>
            </SidebarMenuButton>
        </SidebarMenuItem>
    );
}
function Menu({ items }: { items: Item[] }) {
    return (
        <SidebarGroupContent>
            <SidebarMenu className="gap-2">
                {items.map((item) => (
                    <NavLink key={item.href} item={item} />
                ))}
            </SidebarMenu>
        </SidebarGroupContent>
    );
}
export default function CapitalioSidebar() {
    const auth = usePage().props.auth as {
        user?: { name: string };
        roles?: string[];
    };
    const researcher = auth.roles?.some((role) =>
        ['owner', 'researcher'].includes(role),
    );
    const overview = [
        { name: 'Dashboard', href: '/dashboard', icon: SidebarHomeMutedIcon },
        {
            name: 'Analytics',
            href: '/portfolio?view=analytics',
            icon: SidebarAnalyticsIcon,
        },
        { name: 'Portfolio', href: '/portfolio', icon: SidebarNetWorthIcon },
    ];
    const operations: Item[] = [
        ...(researcher
            ? [{ name: 'Research', href: '/research', icon: SidebarAiIcon }]
            : []),
        { name: 'Agent outputs', href: '/outputs', icon: SidebarFolderIcon },
        { name: 'Community', href: '/community', icon: UsersIcon },
        ...(auth.roles?.includes('owner')
            ? [
                  {
                      name: 'Administration',
                      href: '/admin',
                      icon: ShieldCheckIcon,
                  },
              ]
            : []),
    ];
    return (
        <Sidebar className="capitalio-dashboard border-none! p-0.5">
            <SidebarHeader className="mb-6 flex-row items-start gap-2 px-4 pt-6 pb-0">
                <Link
                    href="/dashboard"
                    className="flex min-w-0 flex-1 items-center gap-3"
                >
                    <div className="flex size-10 shrink-0 items-center justify-center rounded-lg border border-primary bg-linear-to-t from-[color-mix(in_oklab,var(--primary)_80%,black)] to-primary text-xl font-bold text-white">
                        K
                    </div>
                    <span className="truncate text-lg font-medium text-sidebar-accent-foreground">
                        Kernscale
                    </span>
                </Link>
            </SidebarHeader>
            <SidebarContent className="gap-3 px-4 pb-4">
                <SidebarGroup className="gap-2 p-0">
                    <SidebarGroupLabel className="font-section px-2 py-3 text-base font-normal tracking-tight text-muted-foreground/70">
                        Overview
                    </SidebarGroupLabel>
                    <Menu items={overview} />
                </SidebarGroup>
                <Collapsible.Root defaultOpen>
                    <SidebarGroup className="gap-2 p-0">
                        <Collapsible.Trigger className="font-section flex w-full cursor-pointer items-center justify-between px-2 py-3 text-base font-normal tracking-tight text-muted-foreground/70 transition-colors hover:text-muted-foreground [&>svg]:size-2.5! [&>svg]:transition-transform [&>svg]:duration-200 [&>svg]:ease-out data-panel-open:[&>svg]:rotate-180">
                            Operations
                            <SidebarChevronIcon className="shrink-0" />
                        </Collapsible.Trigger>
                        <Collapsible.Panel>
                            <Menu items={operations} />
                        </Collapsible.Panel>
                    </SidebarGroup>
                </Collapsible.Root>
            </SidebarContent>
            <SidebarFooter className="gap-3 px-4 pt-0 pb-5">
                <SidebarMenu className="gap-2">
                    <NavLink
                        item={{
                            name: 'Settings',
                            href: '/settings/profile',
                            icon: SidebarSettingsIcon,
                        }}
                    />
                </SidebarMenu>
                <div className="border-t" />
                <div className="flex min-w-0 items-center gap-3">
                    <Avatar className="size-10 rounded-lg after:rounded-lg">
                        <AvatarFallback className="rounded-lg">
                            {auth.user?.name.slice(0, 1) ?? 'M'}
                        </AvatarFallback>
                    </Avatar>
                    <div className="flex min-w-0 flex-1 flex-col gap-1">
                        <span className="truncate text-base leading-none font-medium text-sidebar-accent-foreground">
                            {auth.user?.name ?? 'Member'}
                        </span>
                        <span className="premium-badge">
                            {auth.roles?.includes('owner')
                                ? 'Owner'
                                : researcher
                                  ? 'Researcher'
                                  : 'Member'}
                        </span>
                    </div>
                    <Button
                        aria-label="Sign out"
                        size="icon"
                        variant="destructive"
                        className="group bg-transparent!"
                        onClick={() => router.post('/logout')}
                    >
                        <SidebarLogoutIcon className="size-4 opacity-70 transition-colors group-hover:opacity-100" />
                    </Button>
                </div>
            </SidebarFooter>
        </Sidebar>
    );
}
