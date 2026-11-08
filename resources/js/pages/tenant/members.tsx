import { Form, Head, Link, setLayoutProps, usePage } from '@inertiajs/react';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { TenantInvitations } from '@/components/tenant-invitations';
import type { InvitationsPage } from '@/components/tenant-invitations';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { dashboard } from '@/routes/tenant';
import { destroy, index, update } from '@/routes/tenant/members';

type Member = {
    id: number;
    name: string;
    email: string;
    is_active: boolean;
    roles: string[];
    can_update_role: boolean;
    can_remove: boolean;
};

type MembersPage = {
    data: Member[];
    current_page: number;
    last_page: number;
    total: number;
};

function MemberRoleForm({
    tenantId,
    member,
}: {
    tenantId: string;
    member: Member;
}) {
    return (
        <Form
            {...update.form({ tenant: tenantId, member: member.id })}
            options={{ preserveScroll: true }}
        >
            {({ processing, errors }) => (
                <div className="space-y-2">
                    <div className="flex items-center gap-2">
                        <Select
                            name="role"
                            defaultValue={
                                member.roles.includes('admin')
                                    ? 'admin'
                                    : member.roles.includes('member')
                                      ? 'member'
                                      : undefined
                            }
                            disabled={processing}
                        >
                            <SelectTrigger
                                className="w-32"
                                aria-label={`Role for ${member.name}`}
                            >
                                <SelectValue placeholder="Choose role" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="admin">Admin</SelectItem>
                                <SelectItem value="member">Member</SelectItem>
                            </SelectContent>
                        </Select>
                        <Button
                            type="submit"
                            variant="outline"
                            size="sm"
                            disabled={processing}
                        >
                            Save
                        </Button>
                    </div>
                    <InputError message={errors.role} />
                </div>
            )}
        </Form>
    );
}

function RemoveMemberDialog({
    tenantId,
    member,
}: {
    tenantId: string;
    member: Member;
}) {
    return (
        <Dialog>
            <DialogTrigger asChild>
                <Button
                    variant="destructive"
                    size="sm"
                    aria-label={`Remove ${member.name}`}
                >
                    Remove
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogTitle>Remove {member.name}?</DialogTitle>
                <DialogDescription>
                    They will lose access to this workspace. Their account and
                    access to other workspaces will remain.
                </DialogDescription>
                <Form
                    {...destroy.form({ tenant: tenantId, member: member.id })}
                    options={{ preserveScroll: true }}
                >
                    {({ processing }) => (
                        <DialogFooter className="gap-2">
                            <DialogClose asChild>
                                <Button
                                    type="button"
                                    variant="secondary"
                                    disabled={processing}
                                >
                                    Cancel
                                </Button>
                            </DialogClose>
                            <Button
                                type="submit"
                                variant="destructive"
                                disabled={processing}
                            >
                                Remove member
                            </Button>
                        </DialogFooter>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}

export default function Members({
    members,
    inviteRoles,
    invitations,
}: {
    members: MembersPage;
    inviteRoles: string[];
    invitations: InvitationsPage | null;
}) {
    const { tenancy, auth } = usePage().props;
    const tenant = tenancy.current!;

    setLayoutProps({
        breadcrumbs: [
            {
                title: tenant.name || 'Untitled workspace',
                href: dashboard({ tenant: tenant.id }),
            },
            { title: 'Members', href: index({ tenant: tenant.id }) },
        ],
    });

    return (
        <>
            <Head title="Members" />
            <div className="space-y-6 p-4 md:p-6">
                <h1 className="sr-only">Workspace members</h1>
                <Heading
                    title="Members"
                    description="View workspace members and manage their access."
                />

                {invitations && (
                    <TenantInvitations
                        key={tenant.id}
                        tenantId={tenant.id}
                        inviteRoles={inviteRoles}
                        invitations={invitations}
                    />
                )}

                <div className="overflow-x-auto rounded-lg border">
                    <table className="w-full min-w-2xl text-left text-sm">
                        <caption className="sr-only">
                            Members of {tenant.name || 'this workspace'}
                        </caption>
                        <thead className="border-b bg-muted/50">
                            <tr>
                                <th scope="col" className="p-4 font-medium">
                                    Name
                                </th>
                                <th scope="col" className="p-4 font-medium">
                                    Role
                                </th>
                                <th scope="col" className="p-4 font-medium">
                                    Status
                                </th>
                                <th scope="col" className="p-4 font-medium">
                                    <span className="sr-only">Actions</span>
                                </th>
                            </tr>
                        </thead>
                        <tbody className="divide-y">
                            {members.data.map((member) => (
                                <tr key={`${tenant.id}-${member.id}`}>
                                    <td className="p-4">
                                        <div className="font-medium">
                                            {member.name}
                                            {member.id === auth.user.id &&
                                                ' (you)'}
                                        </div>
                                        <div className="text-muted-foreground">
                                            {member.email}
                                        </div>
                                    </td>
                                    <td className="p-4">
                                        {member.can_update_role ? (
                                            <MemberRoleForm
                                                key={member.roles.join('-')}
                                                tenantId={tenant.id}
                                                member={member}
                                            />
                                        ) : (
                                            <div className="flex flex-wrap gap-1">
                                                {member.roles.length ? (
                                                    member.roles.map((role) => (
                                                        <Badge
                                                            key={role}
                                                            variant="secondary"
                                                            className="capitalize"
                                                        >
                                                            {role}
                                                        </Badge>
                                                    ))
                                                ) : (
                                                    <span className="text-muted-foreground">
                                                        No role
                                                    </span>
                                                )}
                                            </div>
                                        )}
                                    </td>
                                    <td className="p-4">
                                        <Badge
                                            variant={
                                                member.is_active
                                                    ? 'outline'
                                                    : 'secondary'
                                            }
                                        >
                                            {member.is_active
                                                ? 'Active'
                                                : 'Inactive'}
                                        </Badge>
                                    </td>
                                    <td className="p-4 text-right">
                                        {member.can_remove && (
                                            <RemoveMemberDialog
                                                tenantId={tenant.id}
                                                member={member}
                                            />
                                        )}
                                    </td>
                                </tr>
                            ))}
                            {members.data.length === 0 && (
                                <tr>
                                    <td
                                        colSpan={4}
                                        className="p-8 text-center text-muted-foreground"
                                    >
                                        No members on this page.
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>

                <div className="flex flex-wrap items-center justify-between gap-4">
                    <p className="text-sm text-muted-foreground">
                        {members.total}{' '}
                        {members.total === 1 ? 'member' : 'members'}
                    </p>
                    {members.last_page > 1 && (
                        <nav
                            aria-label="Members pagination"
                            className="flex items-center gap-3"
                        >
                            {members.current_page > 1 && (
                                <Button variant="outline" size="sm" asChild>
                                    <Link
                                        href={index(
                                            { tenant: tenant.id },
                                            {
                                                query: {
                                                    page:
                                                        members.current_page -
                                                        1,
                                                },
                                            },
                                        )}
                                    >
                                        Previous
                                    </Link>
                                </Button>
                            )}
                            <span className="text-sm">
                                Page {members.current_page} of{' '}
                                {members.last_page}
                            </span>
                            {members.current_page < members.last_page && (
                                <Button variant="outline" size="sm" asChild>
                                    <Link
                                        href={index(
                                            { tenant: tenant.id },
                                            {
                                                query: {
                                                    page:
                                                        members.current_page +
                                                        1,
                                                },
                                            },
                                        )}
                                    >
                                        Next
                                    </Link>
                                </Button>
                            )}
                        </nav>
                    )}
                </div>
            </div>
        </>
    );
}
