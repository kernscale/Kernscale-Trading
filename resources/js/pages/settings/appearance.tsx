import { Head } from '@inertiajs/react';
import Heading from '@/components/heading';
import { edit as editAppearance } from '@/routes/appearance';

export default function Appearance() {
    return (
        <>
            <Head title="Appearance settings" />

            <h1 className="sr-only">Appearance settings</h1>

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title="Appearance settings"
                    description="Trading workspace appearance"
                />
                <div className="rounded-lg border border-border bg-card p-4">
                    <p className="text-sm font-medium">Dark workspace</p>
                    <p className="mt-2 text-sm leading-6 text-muted-foreground">
                        Trading uses a consistent dark theme for research,
                        reports and account settings.
                    </p>
                </div>
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
