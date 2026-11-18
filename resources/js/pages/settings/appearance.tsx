import { Head } from '@inertiajs/react';
import { Moon } from 'lucide-react';
import { StatusMark } from '@/components/kit/micro';
import { edit as editAppearance } from '@/routes/appearance';

export default function Appearance() {
    return (
        <>
            <Head title="Appearance settings" />

            <h1 className="sr-only">Appearance settings</h1>

            <div className="flex items-center gap-4 rounded-xl border border-[var(--kit-line)] p-4">
                <span className="flex size-10 items-center justify-center rounded-lg border border-[var(--kit-line)] bg-[#0b0b0d]">
                    <Moon className="size-4" aria-hidden />
                </span>
                <span className="min-w-0 flex-1">
                    <span className="block text-[14px] font-medium">Dark</span>
                    <span className="block text-[12.5px] text-[var(--kit-faint)]">
                        Used across the whole workspace
                    </span>
                </span>
                <StatusMark status="active" />
            </div>
        </>
    );
}

Appearance.layout = {
    breadcrumbs: [
        {
            title: 'Appearance settings',
            href: editAppearance(),
        },
    ],
};
