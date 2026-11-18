// Capitalio registry dashboard-layout: same SidebarProvider dimensions, frame and inset shadow.
import { usePage } from '@inertiajs/react';
import { useEffect, type CSSProperties } from 'react';
import CapitalioSidebar from '@/components/capitalio/app-sidebar';
import CapitalioTopbar from '@/components/capitalio/topbar';
import { Errors } from '@/components/hub/shared';
import { SidebarProvider } from '@/components/ui/sidebar';
import type { BreadcrumbItem } from '@/types';
import '@/components/watermelon/capitalio-dashboard/dashboard.css';
import '@/components/capitalio/capitalio.css';
import '@/components/kit/kit.css';
export default function AppLayout({
    children,
}: {
    breadcrumbs?: BreadcrumbItem[];
    children: React.ReactNode;
}) {
    const page = usePage();
    useEffect(() => {
        document.documentElement.classList.add('dark');
    }, []);
    const research = page.url.startsWith('/research');
    const sourcePage =
        page.url.startsWith('/dashboard') || page.url.startsWith('/portfolio');
    return (
        <SidebarProvider
            defaultOpen
            className="capitalio-dashboard dark no-scrollbar h-svh overflow-hidden"
            style={{ '--sidebar-width': '15.625rem' } as CSSProperties}
        >
            <a
                href="#main-content"
                className="sr-only focus:not-sr-only focus:fixed focus:z-[100] focus:bg-card focus:p-4"
            >
                Skip to content
            </a>
            <CapitalioSidebar />
            <main className="flex min-h-0 min-w-0 flex-1 flex-col overflow-hidden p-0.5 pl-0">
                <div className="kit shadow-custom flex min-h-0 min-w-0 flex-1 flex-col bg-background">
                    <CapitalioTopbar />
                    <div
                        id="main-content"
                        className="min-h-0 flex-1 overflow-y-auto"
                    >
                        <div
                            className={
                                sourcePage
                                    ? ''
                                    : research
                                      ? 'h-full min-h-0 p-2 md:p-3'
                                      : 'p-4 pb-8 md:p-8'
                            }
                        >
                            <Errors
                                errors={
                                    (page.props.errors ?? {}) as Record<
                                        string,
                                        string
                                    >
                                }
                            />
                            {children}
                        </div>
                    </div>
                </div>
            </main>
        </SidebarProvider>
    );
}
