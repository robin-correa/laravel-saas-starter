import { Head, setLayoutProps, usePage } from '@inertiajs/react';
import { PlaceholderPattern } from '@/components/ui/placeholder-pattern';
import { dashboard } from '@/routes';
import { dashboard as tenantDashboard } from '@/routes/tenant';

export default function Dashboard() {
    const { tenancy } = usePage().props;
    const workspaceName = tenancy.current?.name || 'Untitled workspace';
    const dashboardLink = tenancy.current
        ? tenantDashboard({ tenant: tenancy.current.id })
        : dashboard();

    setLayoutProps({
        breadcrumbs: [{ title: workspaceName, href: dashboardLink }],
    });

    return (
        <>
            <Head title="Dashboard" />
            <div className="flex h-full flex-1 flex-col gap-4 overflow-x-auto rounded-xl p-4">
                <header className="space-y-1">
                    <h1 className="text-2xl font-semibold tracking-tight">
                        {workspaceName}
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        Your workspace dashboard.
                    </p>
                </header>
                <div className="grid auto-rows-min gap-4 md:grid-cols-3">
                    <div className="relative aspect-video overflow-hidden rounded-xl border border-sidebar-border/70 dark:border-sidebar-border">
                        <PlaceholderPattern className="absolute inset-0 size-full stroke-neutral-900/20 dark:stroke-neutral-100/20" />
                    </div>
                    <div className="relative aspect-video overflow-hidden rounded-xl border border-sidebar-border/70 dark:border-sidebar-border">
                        <PlaceholderPattern className="absolute inset-0 size-full stroke-neutral-900/20 dark:stroke-neutral-100/20" />
                    </div>
                    <div className="relative aspect-video overflow-hidden rounded-xl border border-sidebar-border/70 dark:border-sidebar-border">
                        <PlaceholderPattern className="absolute inset-0 size-full stroke-neutral-900/20 dark:stroke-neutral-100/20" />
                    </div>
                </div>
                <div className="relative min-h-[100vh] flex-1 overflow-hidden rounded-xl border border-sidebar-border/70 md:min-h-min dark:border-sidebar-border">
                    <PlaceholderPattern className="absolute inset-0 size-full stroke-neutral-900/20 dark:stroke-neutral-100/20" />
                </div>
            </div>
        </>
    );
}
