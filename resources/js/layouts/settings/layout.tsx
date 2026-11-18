import { Link } from '@inertiajs/react';
import { Palette, ShieldCheck, UserRound } from 'lucide-react';
import type { PropsWithChildren } from 'react';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { cn, toUrl } from '@/lib/utils';
import { edit as editAppearance } from '@/routes/appearance';
import { edit } from '@/routes/profile';
import { edit as editSecurity } from '@/routes/security';

const items = [
    { title: 'Profile', href: edit(), icon: UserRound },
    { title: 'Security', href: editSecurity(), icon: ShieldCheck },
    { title: 'Appearance', href: editAppearance(), icon: Palette },
];

export default function SettingsLayout({ children }: PropsWithChildren) {
    const { isCurrentOrParentUrl } = useCurrentUrl();

    return (
        <div className="mx-auto w-full max-w-3xl px-4 pt-2 pb-12 md:px-8">
            <nav
                aria-label="Settings"
                className="mb-8 flex gap-1 overflow-x-auto border-b border-[var(--kit-line)]"
            >
                {items.map((item) => {
                    const active = isCurrentOrParentUrl(item.href);
                    return (
                        <Link
                            key={toUrl(item.href)}
                            href={item.href}
                            aria-current={active ? 'page' : undefined}
                            className={cn(
                                '-mb-px flex shrink-0 items-center gap-2 border-b-2 px-3 py-2.5 text-[13.5px] transition-colors',
                                active
                                    ? 'border-foreground text-foreground'
                                    : 'border-transparent text-[var(--kit-faint)] hover:text-foreground',
                            )}
                        >
                            <item.icon className="size-4" aria-hidden />
                            {item.title}
                        </Link>
                    );
                })}
            </nav>
            <section className="space-y-10">{children}</section>
        </div>
    );
}
