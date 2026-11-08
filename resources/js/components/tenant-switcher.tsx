import { Link, usePage } from '@inertiajs/react';
import { Building2, Check, ChevronsUpDown, Plus } from 'lucide-react';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
    useSidebar,
} from '@/components/ui/sidebar';
import { dashboard } from '@/routes/tenant';
import { create } from '@/routes/workspaces';

export function TenantSwitcher() {
    const { tenancy } = usePage().props;
    const { isMobile, state, setOpenMobile } = useSidebar();
    const current = tenancy.current;
    const label = current
        ? current.name || 'Untitled workspace'
        : 'Select a workspace';

    return (
        <SidebarMenu>
            <SidebarMenuItem>
                <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                        <SidebarMenuButton
                            size="lg"
                            className="data-[state=open]:bg-sidebar-accent"
                            aria-label={`Switch workspace: ${label}`}
                            data-test="tenant-switcher"
                        >
                            <Building2
                                className="size-5 shrink-0"
                                aria-hidden
                            />
                            <span className="truncate group-data-[collapsible=icon]:hidden">
                                {label}
                            </span>
                            <ChevronsUpDown
                                className="ml-auto size-4 group-data-[collapsible=icon]:hidden"
                                aria-hidden
                            />
                        </SidebarMenuButton>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent
                        className="max-h-80 w-(--radix-dropdown-menu-trigger-width) min-w-56 overflow-y-auto rounded-lg"
                        align="start"
                        side={
                            isMobile || state !== 'collapsed'
                                ? 'bottom'
                                : 'right'
                        }
                    >
                        <DropdownMenuLabel>Workspaces</DropdownMenuLabel>
                        <DropdownMenuSeparator />
                        {tenancy.available.map((workspace) => (
                            <DropdownMenuItem key={workspace.id} asChild>
                                <Link
                                    href={dashboard({ tenant: workspace.id })}
                                    aria-current={
                                        current?.id === workspace.id
                                            ? 'page'
                                            : undefined
                                    }
                                    onClick={() => setOpenMobile(false)}
                                >
                                    <Building2 className="size-4" aria-hidden />
                                    <span className="truncate">
                                        {workspace.name || 'Untitled workspace'}
                                    </span>
                                    {current?.id === workspace.id && (
                                        <Check
                                            className="ml-auto size-4"
                                            aria-hidden
                                        />
                                    )}
                                </Link>
                            </DropdownMenuItem>
                        ))}
                        {tenancy.available.length > 0 && (
                            <DropdownMenuSeparator />
                        )}
                        <DropdownMenuItem asChild>
                            <Link
                                href={create()}
                                onClick={() => setOpenMobile(false)}
                            >
                                <Plus className="size-4" aria-hidden />
                                Create workspace
                            </Link>
                        </DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>
            </SidebarMenuItem>
        </SidebarMenu>
    );
}
